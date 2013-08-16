<?php
/*
 * Name: Cron Uploader
 * Author: YaoiFox <admin@yaoifox.com>
 * Link: http://www.yaoifox.com/
 * License: GPLv2
 * Description: Uploads images automatically using Cron Jobs
 * Documentation:
 * 	This cron uploader is fairly easy to use but has to be configured first.
 * 	1. Install & activate this plugin.
 * 	
 * 	2. Upload your images you want to be uploaded to the queue directory. (default: shimmie2/data/cron_uploader/queue/)
 * 		You can find the queue Directory Path of your queue in your Board Config.
 * 		This also supports directory names to be used as tags.
 * 	
 * 	3. Go to the Board Config to the Cron Uploader menu and copy the "Cron Command".
 * 	
 * 	4. Create a cron job or something else that can open a url on specified times.
 * 		You can find the Cron Command in the Board Config.
 * 		If you're not sure how to do this, you can give the command to your web host and you can ask them to create the cron job for you.
 * 		When you create the cron job, you choose how often to upload a new image.
 * 
 * 	5. When the cron command is set up, your image queue will upload x file(s) at the specified times.
 * 		You can see any uploads or failed uploads in the log file. (default: shimmie2/data/cron_uploader/uploads.log)
 * 		Your uploaded images will be moved to the "uploaded" directory, it's recommended that you remove everything out of this directory from time to time.
 * 		(default: shimmie2/data/cron_uploader/uploaded/)
 * 		
 * 	Whenever the url in that cron job command is opened, a new file will upload from the queue.
 * 	So when you want to manually upload an image, all you have to do is open the link once.
 * 	This link can be found under "Cron Command" in the board config, just remove the "wget " part and only the url remains.
 */
class CronUploader extends Extension {
	
	/**
	 * Lists all log events this session
	 * @var string
	 */
	private $upload_info = "";
	
	/**
	 * Lists all files & info required to upload.
	 * @var array
	 */
	private $image_queue = array();
	
	/**
	 * Cron Uploader root directory
	 * @var string
	 */
	private $root_dir = "";
	
	/**
	 * Checks if the cron upload page has been accessed
	 * and initializes the upload.
	 * @param PageRequestEvent $event
	 */
	public function onPageRequest(PageRequestEvent $event) {
		global $config;
		
		if ($event->page_matches ( "cron_upload" )) {
			$key = $config->get_string ( "cron_uploader_key", "" );
			
			if ($key != "" && $event->get_arg ( 0 ) == $key) {
				set_time_limit ( 0 );
				
				// Set vars
				$this->root_dir = $config->get_string("cron_uploader_dir", "");
				
				// Process upload & log
				$this->process_upload(); // Start upload
				$this->handle_log(); // Display & save upload log
			}
		}
	}
	

	public function onInitExt(InitExtEvent $event) {
		global $config;
		// Set default values
		$key = $this->generate_key ();
		$dir = $this->set_dir ();
		$config->set_default_int ( 'cron_uploader_count', 1 );
		$config->set_default_string ( 'cron_uploader_key', $key );
	}
	
	public function onSetupBuilding(SetupBuildingEvent $event) {
		global $config;
		$this->root_dir = $this->set_dir();
		$cron_url = make_http(make_link("/cron_upload/" . $config->get_string('cron_uploader_key', 'invalid key' )));
		$cron_cmd = "wget $cron_url";
		
		$queue_dir = $this->root_dir . "/queue";
		$uploaded_dir = $this->root_dir . "/uploaded";
		$failed_dir = $this->root_dir . "/failed_to_upload";
		
		$queue_dirinfo = $this->scan_dir($queue_dir);
		$uploaded_dirinfo = $this->scan_dir($uploaded_dir);
		$failed_dirinfo = $this->scan_dir($failed_dir);

		$sb = new SetupBlock ( "Cron Uploader" );
		$sb->add_label ( "<b>Settings</b><br>" );
		$sb->add_int_option ( "cron_uploader_count", "How many to upload each time" );
		$sb->add_text_option ( "cron_uploader_dir", "<br>Set Cron Uploader root directory<br>");
		$sb->add_label ( "<br><br>
				<b>Information</b>
				<br>
				<table style='width:470px;'>
				<tr>
				<td style='width:90px;'><b>Directory</b></td>
				<td style='width:90px;'><b>Files</b></td>
				<td style='width:90px;'><b>Size (MB)</b></td>
				<td style='width:200px;'><b>Directory Path</b></td>
				</tr><tr>
				<td>Queue</td>
				<td>{$queue_dirinfo['total_files']}</td>
				<td>{$queue_dirinfo['total_mb']}</td>
				<td><input type='text' style='width:150px;' value='$queue_dir'></td>
				</tr><tr>
				<td>Uploaded</td>
				<td>{$uploaded_dirinfo['total_files']}</td>
				<td>{$uploaded_dirinfo['total_mb']}</td>
				<td><input type='text' style='width:150px;' value='$uploaded_dir'></td>
				</tr><tr>
				<td>Failed</td>
				<td>{$failed_dirinfo['total_files']}</td>
				<td>{$failed_dirinfo['total_mb']}</td>
				<td><input type='text' style='width:150px;' value='$failed_dir'></td>
				</tr></table>
				
				<br>Cron Command: <input type='text' size='60' value='$cron_cmd'><br>
				Create a cron job with the command above.<br/>
				Read the documentation if you're not sure what to do.<br>");
		$event->panel->add_block ( $sb );
	}
	
	/*
	 * Generates a unique key for the website to prevent unauthorized access.
	 */
	private function generate_key() {
		$length = 20;
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$randomString = '';
		
		for($i = 0; $i < $length; $i ++) {
			$randomString .= $characters [rand ( 0, strlen ( $characters ) - 1 )];
		}
		
		return $randomString;
	}
	
	/*
	 * Set the directory for the image queue. If no directory was given, set it to the default directory.
	 */
	private function set_dir() {
		global $config;
		// Determine directory (none = default)
		$dir = $this->root_dir;
		
		if ($dir == "")
		{
			$dir = $_SERVER ['DOCUMENT_ROOT'] . "/data/cron_uploader";
			$config->set_default_string ('cron_uploader_dir', $dir);
		}
			
		// Make the directory if it doesn't exist yet
		if (!is_dir($dir . "/queue/")) 
			mkdir ( $dir . "/queue/", 0755, true );
		if (!is_dir($dir . "/uploaded/")) 
			mkdir ( $dir . "/uploaded/", 0755, true );
		if (!is_dir($dir . "/failed_to_upload/")) 
			mkdir ( $dir . "/failed_to_upload/", 0755, true );
		
		return $dir;
	}
	
	/**
	 * Returns amount of files & total size of dir.
	 * @param unknown $path
	 * @return multitype:number
	 */
	function scan_dir($path){
		$ite=new RecursiveDirectoryIterator($path);
	
		$bytestotal=0;
		$nbfiles=0;
		foreach (new RecursiveIteratorIterator($ite) as $filename=>$cur) {
			$filesize = $cur->getSize();
			$bytestotal += $filesize;
			$nbfiles++;			
		}
	
		$size_mb = $bytestotal / 1048576; // to mb
		$size_mb = number_format($size_mb, 2, '.', '');
		return array('total_files'=>$nbfiles,'total_mb'=>$size_mb);
	}
	
	/**
	 * Uploads the image & handles everything
	 * @param number $count to upload a non-config amount of imgs
	 * @return boolean returns true if the upload was successful
	 */
	public function process_upload($count = 0) {
		global $config;
		$this->generate_image_queue ();
		
		// Gets amount of imgs to upload
		if ($count == 0) $count = $config->get_int ( "cron_uploader_count", 1 );
		
		// Throw exception if there's nothing in the queue
		if (count($this->image_queue) == 0) {
			$this->add_upload_info("Your queue is empty so nothing could be uploaded.");
			return false;
		}
		
		// Randomize Images
		shuffle($this->image_queue);
		

		// Upload the file(s)
		for ($i = 0; $i < $count; $i++) {
			$img = $this->image_queue[$i];
			
			try {
				$this->add_image($img[0], $img[1], $img[2]);
				$newPath = $this->move_uploaded($img[0], $img[1], false);
				
			}
			catch (Exception $e) {
				$newPath = $this->move_uploaded($img[0], $img[1], true);
			}
			
			// Remove img from queue array
			unset($this->image_queue[$i]);
		}
		
		return true;
	}
	
	
	private $attempt_count = 0;
	private function move_uploaded($path, $filename,  $corrupt = false) {
		global $config;
		
		// Create 
		$newDir = $this->root_dir;
		
		// Determine which dir to move to
		if ($corrupt) {
			// Move to corrupt dir
			$newDir .= "/failed_to_upload/";
			$info = "ERROR: Image was not uploaded.";
		}
		else {
			$newDir .= "/uploaded/";
			$info = "Image successfully uploaded. ";
		}
		
		// move file to correct dir
		$newPath = $newDir . $filename;
		rename($path, $newPath);
		
		$this->add_upload_info($info . "Image \"$filename\" moved from queue to \"$newPath\".");
	}
	

	 /**
	 * moves a directory up or gets the directory of a file
	 *
	 * @param string $path	Path to modify
	 * @param int $depth	Amount of directories to go up
	 * @return unknown		Path to correct Directory
	 */
	private function move_directory_up($path, $depth=1)
	{
		$path = str_replace("//", "/", $path);
		$array = explode("/", $path);
		
		for ($i = 0; $i < $depth; $i++) {
			$to_remove = count($array) -1; // Gets number of element to remove
			unset($array[$to_remove]);
		}
	
		return implode("/", $array);
	}
	
	/**
	 * Generate the necessary DataUploadEvent for a given image and tags.
	 */
	private function add_image($tmpname, $filename, $tags) {
		assert ( file_exists ( $tmpname ) );
		
		$pathinfo = pathinfo ( $filename );
		if (! array_key_exists ( 'extension', $pathinfo )) {
			throw new UploadException ( "File has no extension" );
		}
		$metadata ['filename'] = $pathinfo ['basename'];
		$metadata ['extension'] = $pathinfo ['extension'];
		$metadata ['tags'] = $tags;
		$metadata ['source'] = null;
		$event = new DataUploadEvent ( $tmpname, $metadata );
		send_event ( $event );
		
		// Generate info message
		$infomsg = ""; // Will contain info message
		if ($event->image_id == -1)
			$infomsg = "File type not recognised. Filename: {$filename}";
		else $infomsg = "Image uploaded. ID: {$event->image_id} - Filename: {$filename} - Tags: {$tags}";
		$msgNumber = $this->add_upload_info($infomsg);
		
	}
	
	private function generate_image_queue($base = "", $subdir = "") {
		global $config;
		
		if ($base == "")
			$base = $this->set_dir() . "/queue";
		
		if (! is_dir ( $base )) {
			$this->add_upload_info("Image Queue Directory could not be found at \"$base\".");
			return array();
		}
		
		foreach ( glob ( "$base/$subdir/*" ) as $fullpath ) {
			$fullpath = str_replace ( "//", "/", $fullpath );
			$shortpath = str_replace ( $base, "", $fullpath );
			
			if (is_link ( $fullpath )) {
				// ignore
			} else if (is_dir ( $fullpath )) {
				$this->generate_image_queue ( $base, str_replace ( $base, "", $fullpath ) );
			} else {
				$pathinfo = pathinfo ( $fullpath );		
				$matches = array ();
				
				if (preg_match ( "/\d+ - (.*)\.([a-zA-Z]+)/", $pathinfo ["basename"], $matches )) {
					$tags = $matches [1];
				} else {
					$tags = $subdir;
					$tags = str_replace ( "/", " ", $tags );
					$tags = str_replace ( "__", " ", $tags );
					if ($tags == "") $tags = " ";
					$tags = trim ( $tags );
				}
				
				$img = array (
						0 => $fullpath,
						1 => $pathinfo ["basename"],
						2 => $tags 
				);
				array_push ($this->image_queue, $img );
			}
		}
	}
	
	/**
	 * Adds a message to the info being published at the end
	 * @param string $text
	 * @param int $addon Enter a value to modify an existing value (enter value number)
	 */
	private function add_upload_info($text, $addon = 0) {
		$info = $this->upload_info;
		$time = "[" .date('Y-m-d H:i:s'). "]";
		
		// If addon function is not used
		if ($addon == 0) {
			$this->upload_info .=  "$time $text\r\n";
			
			// Returns the number of the current line
			$currentLine = substr_count($this->upload_info, "\n") -1;
			return $currentLine;
		}
		
		// else if addon function is used, select the line & modify it
		$lines = substr($info, "\n"); // Seperate the string to array in lines
		$lines[$addon] = "$line[$addon] $text"; // Add the content to the line
		$this->upload_info = implode("\n", $lines); // Put string back together & update
		
		return $addon; // Return line number
	}
	
	/**
	 * This is run at the end to display & save the log.
	 */
	private function handle_log() {
		global $page, $config;
		
		// Display message
		$page->set_mode("data");
		$page->set_type("text/plain");
		$page->set_data($this->upload_info);
		
		// Save log
		$log_path = $this->root_dir . "/uploads.log";
		
		if (file_exists($log_path))
			$prev_content = file_get_contents($log_path);
		else $prev_content = "";
		 
		$content = $prev_content ."\r\n".$this->upload_info;
		file_put_contents ($log_path, $content);
	}
}
?>