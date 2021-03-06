<?php defined('SYSPATH') or die('No direct script access.');
/**
 * @author     John Etherton <john@ethertontech.com>
 * @package    Enhanced Map, Ushahidi Plugin - https://github.com/jetherton/enhancedmap
 * @license	   GNU Lesser GPL (LGPL) Rights pursuant to Version 3, June 2007
 * @copyright  2012 Etherton Technologies Ltd. <http://ethertontech.com>
 * @Date	   2011-06-09
 * Purpose:	   This controller creates a bitmap map. For leagal reasons this can only be used with Open Street Maps
 * This is experimental and is not activated by default.
 * Inputs:     Internal calls from modules
 * Outputs:    A map for viewing by users
 *
 * The Enhanced Map, Ushahidi Plugin is free software: you can redistribute
 * it and/or modify it under the terms of the GNU Lesser General Public License
 * as published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * The Enhanced Map, Ushahidi Plugin is distributed in the hope that it will
 * be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with the Enhanced Map, Ushahidi Plugin.  If not, see
 * <http://www.gnu.org/licenses/>.
 *
 * Changelog:
 * 2011-06-09:  Etherton - Initial release
 *
 * Developed by Etherton Technologies Ltd.
 */
//TODO: Way more error checking!!!!!! and some graceful failing.

	class Stitch_Controller extends Controller
	{
		public $TEMP_DIR = '/var/www/liberia/Ushahidi_Web/media/uploads';
		public $TEMP_URL = '/liberia/Ushahidi_Web/media/uploads';
			


		/**
		 * Function: index
		 *
		 * Description: Creates an image of the map and then returns the URL to that image
		 *
		 * Parameters(POST)
		 * - width - width of the map to render
		 * - height - height of the map to render
		 * - tiles - json encoded info about the tiles needed to render the map
		 * - features- json encoded data about the features on the map (dots and polygons)
		 * - viewport - json encoded information about the viewport on the user's client
		 *
		 * Views:enhancedmap/big_map_header, enhancedmap/big_map_footer
		 *
		 * Results: An image of the map is created and the url is returned
		 * 
		 * TODO: clean up old image files
		 */
  		public function index()
		{
			$this->TEMP_DIR = Kohana::config('upload.directory', TRUE);
			$this->TEMP_URL = url::site()."media/uploads";
			
					
			// fetch the request params, and generate the name of the tempfile and its URL
			$width    = @$_POST['width'];  if (!$width) $width = 1024;
			$height   = @$_POST['height']; if (!$height) $height = 768;
			$tiles    = json_decode(@$_POST['tiles']);
			//$tiles    = json_decode(stripslashes(@$_POST['tiles'])); // use this if you use magic_quotes_gpc
			$random   = md5(microtime().mt_rand());
			$file     = sprintf("%s/%s.png", $this->TEMP_DIR, $random );
			$url      = sprintf("%s/%s.png", $this->TEMP_URL, $random );
		
			// lay down an image canvas
			// Notice: in MapServer if you have set a background color
			// (eg. IMAGECOLOR 60 100 145) that color is your transparent value
			// $transparent = imagecolorallocatealpha($image,60,100,145,127);
			$image = imagecreatetruecolor($width,$height);
			imagefill($image,0,0, imagecolorallocate($image,255,255,255) ); // fill with white
					
			// loop through the tiles, blitting each one onto the canvas
			if(is_array($tiles))
			{		  
				foreach ($tiles as $tile) 
				{
					// try to convert relative URLs into full URLs
					// this could probably use some improvement
					$tile->url = urldecode($tile->url);
					if (substr($tile->url,0,4)!=='http') 
					{
						$tile->url = preg_replace('/^\.\//',dirname($_SERVER['REQUEST_URI']).'/',$tile->url);
						$tile->url = preg_replace('/^\.\.\//',dirname($_SERVER['REQUEST_URI']).'/../',$tile->url);
						$tile->url = sprintf("%s://%s:%d/%s", isset($_SERVER['HTTPS'])?'https':'http', $_SERVER['SERVER_ADDR'], $_SERVER['SERVER_PORT'], $tile->url);
					}
					$tile->url = str_replace(' ','+',$tile->url);     
					
					// fetch the tile into a temp file, and analyze its type; bail if it's invalid
					$tempfile =  sprintf("%s/%s.img", $this->TEMP_DIR, md5(microtime().mt_rand()) );
					file_put_contents($tempfile,file_get_contents($tile->url));
					list($tilewidth,$tileheight,$tileformat) = @getimagesize($tempfile);
					if (!$tileformat)
					{ 
						continue;
					}
					
					// load the tempfile's image, and blit it onto the canvas
					switch ($tileformat) 
					{
						case IMAGETYPE_GIF:
						$tileimage = imagecreatefromgif($tempfile);
						break;
						case IMAGETYPE_JPEG:
						$tileimage = imagecreatefromjpeg($tempfile);
						break;
						case IMAGETYPE_PNG:
						$tileimage = imagecreatefrompng($tempfile);
						break;
					}
					$this->imagecopymerge_alpha($image, $tileimage, $tile->x, $tile->y, 0, 0, $tilewidth, $tileheight, $tile->opacity);
				}
			}
			else
			{
				//something went wrong and we're not getting tiles
				echo "\r\n\r\n\r\n Tiles are: \r\n";
				echo Kohana::debug($tiles);	
				return;		
			}
					  
			/////////////////////////////////////////////////////////////////
			//do dots and polygons
			/////////////////////////////////////////////////////////////////

			$features = json_decode(@$_POST['features']);
			$viewport = json_decode(@$_POST['viewport']);
			
			$image = $this->handleDotsAndPolygons($image, $features, $viewport);			                
			
			
			//save to disk and tell the client where they can pick it up			
			imagepng($image, $file, 1);
			print $url;
		}
		
		
		

		/**
		 * Function: handleDotsAndPolygons
		 *
		 * Description: Processes the dots and polygons
		 *
		 * @param handle $image - Handle to the image
		 * @param array $features - Data about the polygons and dots to render on the image
		 * @param array $viewport - Data about the client's view port
		 *
		 * Views: 
		 *
		 * Results: an image with dots and polygons
		 */
		private function handleDotsAndPolygons($image, $features, $viewport)
		{
			foreach($features as $feature_array) 
			{
				if(count($feature_array) > 0) 
				{ 
					foreach($feature_array as $feature) 
					{
						if($feature->type == "point") 
						{
							$image = $this->handleDots($image, $feature, $viewport);
						}
						elseif($feature->type == "polygon")
						{
							$image = $this->handlePolygons($image, $feature, $viewport);
						} //end of dealing wtih polygons
					} 
				} 
			}
			return $image; 
		}
	
		
		
		
		
		
	  

		/**
		 * Function: handleDots
		 *
		 * Description: Processes the dots and polygons
		 *
		 * @param handle $image - Handle to the image
		 * @param array $feature - Data about the dot to render on the image
		 * @param array $viewport - Data about the client's view port
		 *
		 * Views:
		 *
		 * Results: an image with dots
		 */
		private function handleDots($image, $feature, $viewport)
		{
			if($feature->style) 
			{
				$dst_x = $feature->x; 
				$dst_y = $feature->y; 
				if($feature->style->externalGraphic) 
				{
					// Draw the external graphic 
					$tempfilename = basename($feature->style->externalGraphic); 
					$tempfile =  sprintf("%s".DS."%s", $this->TEMP_DIR, $tempfilename); 
					if(!file_exists($tempfile)) 
					{ 
						$tempfiles[] = $tempfile; 
						file_put_contents($tempfile,file_get_contents($this->relative_to_absolute_url($feature->style->externalGraphic))); 
					} 
					list($fwidth, $fheight, $fformat) = @getimagesize($tempfile); 
					$feature_image = $this->image_create_from_format($fformat, $tempfile); 
					$dst_x = $dst_x - ($fwidth / 2); 
					$dst_y = $dst_y - ($fheight / 2); 
					imagecopy($image, $feature_image, $dst_x, $dst_y, 0, 0, $fwidth, $fheight); 
				}
				else 
				{ 
					// something needs to be drawn 
					//echo Kohana::debug($feature->style);
					//set the width
					
					if($feature->style->strokeWidth)
					{
						imagesetthickness($image, intval(floatval($feature->style->strokeWidth) * 1.5));
					}
					else
					{
						imagesetthickness($image, 2);
					}
					
					//draw the fill
					$opacity = intval(127-(floatval($feature->style->fillOpacity) * 127));      
					$color_rgb = $this->html2rgb($feature->style->fillColor); 
					$color = imagecolorallocatealpha($image, $color_rgb[0], $color_rgb[1], $color_rgb[2], $opacity); 
					$radius = $feature->style->pointRadius * 2; 
					imagefilledellipse($image, $dst_x, $dst_y, $radius, $radius, $color); 
					//draw the line
					$opacity = intval(127-(floatval($feature->style->strokeOpacity) * 127));      
					$color_rgb = $this->html2rgb($feature->style->strokeColor); 
					$color = imagecolorallocatealpha($image, $color_rgb[0], $color_rgb[1], $color_rgb[2], $opacity); 
					$radius = $feature->style->pointRadius * 2; 
					imageellipse($image, $dst_x, $dst_y, $radius, $radius, $color);
				} 
				if($feature->style->label != "") 
				{			
					//draw the fonts      
					$color_rgb = $this->html2rgb($feature->style->fontColor); 
					$color = imagecolorallocatealpha($image, $color_rgb[0], $color_rgb[1], $color_rgb[2], 0); 
					imagestring($image, 4, $dst_x, $dst_y, $feature->style->label, $color); 
				} 
			}
			
			return $image;
		}
		
		
		
		
		

		/**
		 * Function: handlePolygons
		 *
		 * Description: Processes the dots and polygons
		 *
		 * @param handle $image - Handle to the image
		 * @param array $feature - Data about the polygon to render on the image
		 * @param array $viewport - Data about the client's view port
		 *
		 * Views:
		 *
		 * Results: an image with polygons
		 */
		private function handlePolygons($image, $feature, $viewport)
		{
			//make sure we have style, can't live with out that
			if(!$feature->style) 
			{
				return $image;
			}
			
			//echo Kohana::debug($feature->style);
			//set the right thickness			
			if($feature->style->strokeWidth)
			{
				imagesetthickness($image, intval(floatval($feature->style->strokeWidth) * 1.5 ));
			}
			else
			{
				imagesetthickness($image, 2);
			}
			//create the right brush
			//hack to make opacity look right
			//TODO figure this and and fix it
			$opacity = intval(200-(floatval($feature->style->fillOpacity) * 127));
			if($opacity>120)
			{
				$opacity = 120;
			}   
			                                		
			$color_rgb = $this->html2rgb($feature->style->fillColor);
			$fillColor = imagecolorallocatealpha($image, $color_rgb[0], $color_rgb[1], $color_rgb[2], $opacity);
			
			
			$opacity = intval(127-(floatval($feature->style->strokeOpacity) * 127));
			                 
			$color_rgb = $this->html2rgb($feature->style->strokeColor);
			//TODO figure out how to anti-alais these lines 												
			$LineColor = imagecolorallocatealpha($image, $color_rgb[0], $color_rgb[1], $color_rgb[2], $opacity);
			
			//loop over the polygons
			foreach($feature->components as $polyComponents)
			{
				if($polyComponents->type == "linearRing")
				{
					$points = array();
					foreach($polyComponents->components as $linearRingComponents)
					{
						if($linearRingComponents->type == "point")
						{
							$points[] = $linearRingComponents->x;
							$points[] = $linearRingComponents->y;
						}
					}//end of dealing with a linear ring
					//now draw the polyfon
					imagefilledpolygon($image, $points, count($points)/2, $fillColor);
					imagepolygon($image, $points, count($points)/2, $LineColor);
				}	
			}												
			return $image;
		}
		
		
		
		
		
		
		
		
		
		/**
		 * Function: html2rgb
		 *
		 * Description: turns #RGB into array(r, g, b)
		 *
		 * @param string $color - Handle to the image
		 * @return array - array(r,g,b)		 
		 *
		 * Views:
		 *
		 * Results: an array with r,g,b values
		 */
		public function html2rgb($color)
		{
		    if ($color[0] == '#')
		        $color = substr($color, 1);

		    if (strlen($color) == 6)
		        list($r, $g, $b) = array($color[0].$color[1],
		                                 $color[2].$color[3],
		                                 $color[4].$color[5]);
		    elseif (strlen($color) == 3)
		        list($r, $g, $b) = array($color[0].$color[0], $color[1].$color[1], $color[2].$color[2]);
		    else
		        return false;
		
		    $r = hexdec($r); $g = hexdec($g); $b = hexdec($b);
		
		    return array($r, $g, $b);
		}
	  
	
		  
		  
	
		/**
		 * Function: imagecopymerge_alpha
		 *
		 * Description: Copies part of a src image to a dest image with the given opacity
		 * 
		 * @param handle $dst_im - Handle of destination image
		 * @param handle $src_im - Hanlde of source image
		 * @param int $dst_x - x-coordinate of destination point.
		 * @param int $dst_y - y-coordinate of destination point.
		 * @param int $src_x - x-coordinate of source point.
		 * @param int $src_y - y-coordinate of source point.
		 * @param int $src_w - Source width
		 * @param int $src_h - Source height
		 * @param int $opacity - The two images will be merged according to pct which can range from 0 to 100. When pct = 0, no action is taken, when 100 this function behaves identically to imagecopy() for pallete images, except for ignoring alpha components, while it implements alpha transparency for true colour images.
		 *
		 * Views:
		 *
		 * Results: Something from the source image gets copied to the destination image
		 */
		function imagecopymerge_alpha($dst_im, $src_im, $dst_x, $dst_y, $src_x, $src_y, $src_w, $src_h, $opacity)
		{
			$w = imagesx($src_im);
			$h = imagesy($src_im);
			$cut = imagecreatetruecolor($src_w, $src_h);
			imagecopy($cut, $dst_im, 0, 0, $dst_x, $dst_y, $src_w, $src_h);
			imagecopy($cut, $src_im, 0, 0, $src_x, $src_y, $src_w, $src_h);
			imagecopymerge($dst_im, $cut, $dst_x, $dst_y, $src_x, $src_y, $src_w, $src_h, $opacity);
		}
			
	
		/**
		 * Function: index
		 *
		 * Description: Renders the please wait view
		 *
		 * Views: enhancedmap/waitprint, 
		 *
		 * Results: Renders the please wait view
		 */
		public function wait()
		{
			$view = new View("enhancedmap/waitprint");
			$view->render(true);
		
		}
	  
	}
  ?>
  