<?php

class ViewImageTheme extends Themelet {
	/*
	 * Build a page showing $image and some info about it
	 */
	public function display_page(Image $image, $editor_parts) {
		global $page;

		$metatags = str_replace(" ", ", ", html_escape($image->get_tag_list()));

		$page->set_title("Image {$image->id}: ".html_escape($image->get_tag_list()));
		$page->add_html_header("<meta name=\"keywords\" content=\"$metatags\">");
		$page->add_html_header("<meta property=\"og:title\" content=\"$metatags\">");
		$page->add_html_header("<meta property=\"og:type\" content=\"article\">");
		$page->add_html_header("<meta property=\"og:image\" content=\"".make_http($image->get_thumb_link())."\">");
		$page->add_html_header("<meta property=\"og:url\" content=\"".make_http(make_link("post/view/{$image->id}"))."\">");
		$page->set_heading(html_escape($image->get_tag_list()));
		$page->add_block(new Block("Navigation", $this->build_navigation($image), "left", 0));
		$page->add_block(new Block(null, $this->build_info($image, $editor_parts), "main", 10));
		//$page->add_block(new Block(null, $this->build_pin($image), "main", 11));
	}

	public function display_admin_block(Page $page, $parts) {
		if(count($parts) > 0) {
			$page->add_block(new Block("Image Controls", join("<br>", $parts), "left", 50));
		}
	}


	protected function build_pin(Image $image) {
		global $database;

		if(isset($_GET['search'])) {
			$search_terms = explode(' ', $_GET['search']);
			$query = "search=".url_escape($_GET['search']);
		}
		else {
			$search_terms = array();
			$query = null;
		}

		$h_prev = "<a id='prevlink' href='".make_link("post/prev/{$image->id}", $query)."'>Prev</a>";
		$h_index = "<a href='".make_link()."'>Index</a>";
		$h_next = "<a id='nextlink' href='".make_link("post/next/{$image->id}", $query)."'>Next</a>";

		return "$h_prev | $h_index | $h_next";
	}

	protected function build_navigation(Image $image) {
		$h_pin = $this->build_pin($image);
		$h_search = "
			<p><form action='".make_link()."' method='GET'>
				<input type='hidden' name='q' value='/post/list'>
				<input placeholder='Search' id='search_input' name='search' type='text'>
				<input type='submit' value='Find' style='display: none;'>
			</form>
		";

		return "$h_pin<br>$h_search";
	}

	protected function build_info(Image $image, $editor_parts) {
		global $user;
		$owner = $image->get_owner();
		$h_owner = html_escape($owner->name);
		$h_ip = html_escape($image->owner_ip);
		$i_owner_id = int_escape($owner->id);
		$h_date = autodate($image->posted);

		$html = "";
		$html .= "<p>Uploaded by <a href='".make_link("user/$h_owner")."'>$h_owner</a> $h_date";

		if($user->can("view_ip")) {
			$html .= " ($h_ip)";
		}
		$html .= $this->format_source($image->source);

		$html .= $this->build_image_editor($image, $editor_parts);

		return $html;
	}

	private function format_source($source) {
		if(!is_null($source)) {
			$h_source = html_escape($source);
			if(startsWith($source, "http://") || startsWith($source, "https://")) {
				return " (<a href='$h_source'>source</a>)";
			}
			else {
				return " (<a href='http://$h_source'>source</a>)";
			}
		}
		return "";
	}

	protected function build_image_editor(Image $image, $editor_parts) {
		if(count($editor_parts) == 0) return ($image->is_locked() ? "<br>[Image Locked]" : "");

		$html = make_form(make_link("post/set"))."
					<input type='hidden' name='image_id' value='{$image->id}'>
					<table style='width: 500px;'>
		";
		foreach($editor_parts as $part) {
			$html .= $part;
		}
		$html .= "
						<tr><td colspan='2'><input type='submit' value='Set'></td></tr>
					</table>
				</form>
		";
		return $html;
	}
}
?>
