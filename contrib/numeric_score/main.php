<?php
/**
 * Name: Image Scores (Numeric)
 * Author: Shish <webmaster@shishnet.org>
 * License: GPLv2
 * Description: Allow users to score images
 * Documentation:
 *  Each registered user may vote an image +1 or -1, the
 *  image's score is the sum of all votes.
 */

class NumericScoreSetEvent extends Event {
	var $image_id, $user, $score;

	public function NumericScoreSetEvent($image_id, $user, $score) {
		$this->image_id = $image_id;
		$this->user = $user;
		$this->score = $score;
	}
}

class NumericScore implements Extension {
	var $theme;

	public function receive_event(Event $event) {
		if(is_null($this->theme)) $this->theme = get_theme_object($this);

		if($event instanceof InitExtEvent) {
			global $config;
			if($config->get_int("ext_numeric_score_version", 0) < 1) {
				$this->install();
			}
		}

		if($event instanceof DisplayingImageEvent) {
			global $user;
			if(!$user->is_anonymous()) {
				$html = $this->theme->get_voter_html($event->image);
				$event->page->add_block(new Block("Image Score", $html, "left", 20));
			}
		}

		if(($event instanceof PageRequestEvent) && $event->page_matches("numeric_score_vote")) {
			if(!$event->user->is_anonymous()) {
				$image_id = int_escape($_POST['image_id']);
				$char = $_POST['vote'];
				$score = 0;
				if($char == "up") $score = 1;
				else if($char == "down") $score = -1;
				if($score != 0) send_event(new NumericScoreSetEvent($image_id, $event->user, $score));
				$event->page->set_mode("redirect");
				$event->page->set_redirect(make_link("post/view/$image_id"));
			}
		}

		if($event instanceof NumericScoreSetEvent) {
			$this->add_vote($event->image_id, $event->user->id, $event->score);
		}

		if($event instanceof ImageDeletionEvent) {
			global $database;
			$database->execute("DELETE FROM numeric_score_votes WHERE image_id=?", array($event->image->id));
		}

		if($event instanceof ParseLinkTemplateEvent) {
			$event->replace('$score', $event->image->numeric_score);
		}

		if($event instanceof SearchTermParseEvent) {
			$matches = array();
			if(preg_match("/^score(<|<=|=|>=|>)(\d+)$/", $event->term, $matches)) {
				$cmp = $matches[1];
				$score = $matches[2];
				$event->set_querylet(new Querylet("numeric_score $cmp $score"));
			}
			if(preg_match("/^favou?rite$/", $event->term, $matches)) {
				global $user;
				$event->set_querylet(new Querylet("images.id in (SELECT image_id FROM numeric_score_votes WHERE user_id=? AND score=1)", array($user->id)));
			}
		}
	}

	private function install() {
		global $database;
		global $config;

		if($config->get_int("ext_numeric_score_version") < 1) {
			$database->Execute("ALTER TABLE images ADD COLUMN numeric_score INTEGER NOT NULL DEFAULT 0");
			$database->Execute("CREATE INDEX images__numeric_score ON images(numeric_score)");
			$database->create_table("numeric_score_votes", "
				image_id INTEGER NOT NULL,
				user_id INTEGER NOT NULL,
				score INTEGER NOT NULL,
				UNIQUE(image_id, user_id),
				INDEX(image_id),
				FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE,
				FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
			");
			$config->set_int("ext_numeric_score_version", 1);
		}
		if($config->get_int("ext_numeric_score_version") < 2) {
			$database->Execute("CREATE INDEX numeric_score_votes__user_votes ON numeric_score_votes(user_id, scores)");
			$config->set_int("ext_numeric_score_version", 2);
		}
	}

	private function add_vote($image_id, $user_id, $score) {
		global $database;
		$database->Execute(
			"DELETE FROM numeric_score_votes WHERE image_id=? AND user_id=?",
			array($image_id, $user_id));
		$database->Execute(
			"INSERT INTO numeric_score_votes(image_id, user_id, score) VALUES(?, ?, ?)",
			array($image_id, $user_id, $score));
		$database->Execute(
			"UPDATE images SET numeric_score=(SELECT SUM(score) FROM numeric_score_votes WHERE image_id=?) WHERE id=?",
			array($image_id, $image_id));
	}
}
add_event_listener(new NumericScore());
?>
