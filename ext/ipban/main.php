<?php

use MicroCRUD\InetColumn;
use MicroCRUD\StringColumn;
use MicroCRUD\DateColumn;
use MicroCRUD\TextColumn;
use MicroCRUD\EnumColumn;
use MicroCRUD\Table;

class IPBanTable extends Table
{
    public function __construct(\PDO $db, $token=null)
    {
        parent::__construct($db, $token);

        $this->table = "bans";
        $this->base_query = "
			SELECT * FROM (
				SELECT bans.*, users.name AS banner
				FROM bans JOIN users ON banner_id=users.id
			) AS tbl1
		";

        $this->size = 10;
        $this->columns = [
            new InetColumn("ip", "IP"),
            new EnumColumn("mode", "Mode", ["Block"=>"block", "Firewall"=>"firewall", "Ghost"=>"ghost"]),
            new TextColumn("reason", "Reason"),
            new StringColumn("banner", "Banner"),
            new DateColumn("added", "Added"),
            new DateColumn("expires", "Expires"),
        ];
        $this->order_by = ["expires", "id"];
        $this->flags = [
            "all" => ["((expires > CURRENT_TIMESTAMP) OR (expires IS NULL))", null],
        ];
        $this->create_url = make_link("ip_ban/create");
        $this->delete_url = make_link("ip_ban/delete");

		$this->table_attrs = ["class" => "sortable zebra"];
    }
}

class RemoveIPBanEvent extends Event
{
    public $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }
}

class AddIPBanEvent extends Event
{
    public $ip;
    public $mode;
    public $reason;
    public $expires;

    public function __construct(string $ip, string $mode, string $reason, ?string $expires)
    {
        $this->ip = trim($ip);
        $this->mode = $mode;
        $this->reason = trim($reason);
        $this->expires = $expires;
    }
}

class IPBan extends Extension
{
    public function get_priority(): int
    {
        return 10;
    }

    public function onInitExt(InitExtEvent $event)
    {
        global $config;
        $config->set_default_string(
            "ipban_message",
            '<p>IP <b>$IP</b> has been banned until <b>$DATE</b> by <b>$ADMIN</b> because of <b>$REASON</b>
<p>If you couldn\'t possibly be guilty of what you\'re banned for, the person we banned probably had a dynamic IP address and so do you.
<p>See <a href="http://whatismyipaddress.com/dynamic-static">http://whatismyipaddress.com/dynamic-static</a> for more information.
<p>$CONTACT'
        );
        $this->check_ip_ban();
    }

    public function onPageRequest(PageRequestEvent $event)
    {
        if ($event->page_matches("ip_ban")) {
			global $database, $page, $user;
            if ($user->can(Permissions::BAN_IP)) {
                if ($event->get_arg(0) == "create") {
					$user->ensure_authed();
					$input = validate_input(["c_ip"=>"string", "c_mode"=>"string", "c_reason"=>"string", "c_expires"=>"optional,date"]);
					send_event(new AddIPBanEvent($input['c_ip'], $input['c_mode'], $input['c_reason'], $input['c_expires']));
					flash_message("Ban for {$input['c_ip']} added");
					$page->set_mode(PageMode::REDIRECT);
					$page->set_redirect(make_link("ip_ban/list"));
                } elseif ($event->get_arg(0) == "delete") {
					$user->ensure_authed();
					$input = validate_input(["d_id"=>"int"]);
					send_event(new RemoveIPBanEvent($input['d_id']));
					flash_message("Ban removed");
					$page->set_mode(PageMode::REDIRECT);
					$page->set_redirect(make_link("ip_ban/list"));
                } elseif ($event->get_arg(0) == "list") {
					$_GET['c_banner'] = $user->name;
					$_GET['c_added'] = date('Y-m-d');
                    $t = new IPBanTable($database->raw_db());
                    $t->token = $user->get_auth_token();
                    $t->inputs = $_GET;
                    $table = $t->table($t->query());
                    $this->theme->display_bans($page, $table, $t->paginator());
                }
            } else {
                $this->theme->display_permission_denied();
            }
        }
    }

    public function onSetupBuilding(SetupBuildingEvent $event)
    {
        $sb = new SetupBlock("IP Ban");
        $sb->add_longtext_option("ipban_message", 'Message to show to banned users:<br>(with $IP, $DATE, $ADMIN, $REASON, and $CONTACT)');
        $event->panel->add_block($sb);
    }

    public function onPageSubNavBuilding(PageSubNavBuildingEvent $event)
    {
        global $user;
        if ($event->parent==="system") {
            if ($user->can(Permissions::BAN_IP)) {
                $event->add_nav_link("ip_bans", new Link('ip_ban/list'), "IP Bans", NavLink::is_active(["ip_ban"]));
            }
        }
    }

    public function onUserBlockBuilding(UserBlockBuildingEvent $event)
    {
        global $user;
        if ($user->can(Permissions::BAN_IP)) {
            $event->add_link("IP Bans", make_link("ip_ban/list"));
        }
    }

    public function onAddIPBan(AddIPBanEvent $event)
    {
        global $cache, $user, $database;
        $sql = "INSERT INTO bans (ip, mode, reason, expires, banner_id) VALUES (:ip, :mode, :reason, :expires, :admin_id)";
        $database->Execute($sql, ["ip"=>$event->ip, "mode"=>$event->mode, "reason"=>$event->reason, "expires"=>$event->expires, "admin_id"=>$user->id]);
        $cache->delete("ip_bans_sorted");
        log_info("ipban", "Banned {$event->ip} because '{$event->reason}' until {$event->expires}");
    }

    public function onRemoveIPBan(RemoveIPBanEvent $event)
    {
        global $cache, $database;
        $ban = $database->get_row("SELECT * FROM bans WHERE id = :id", ["id"=>$event->id]);
        if ($ban) {
            $database->Execute("DELETE FROM bans WHERE id = :id", ["id"=>$event->id]);
            $cache->delete("ip_bans_sorted");
            log_info("ipban", "Removed {$ban['ip']}'s ban");
        }
    }

    public function onDatabaseUpgrade(DatabaseUpgradeEvent $event)
    {
        global $database;
        global $config;

        // shortcut to latest
        if ($this->get_version("ext_ipban_version") < 1) {
            $database->create_table("bans", "
				id SCORE_AIPK,
				banner_id INTEGER NOT NULL,
				ip SCORE_INET NOT NULL,
				reason TEXT NOT NULL,
				added TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				expires TIMESTAMP NULL DEFAULT NULL,
				FOREIGN KEY (banner_id) REFERENCES users(id) ON DELETE CASCADE,
			");
            $database->execute("CREATE INDEX bans__expires ON bans(expires)");
            $this->set_version("ext_ipban_version", 10);
        }

        // ===

        if ($this->get_version("ext_ipban_version") < 1) {
            $database->Execute("CREATE TABLE bans (
				id int(11) NOT NULL auto_increment,
				ip char(15) default NULL,
				date TIMESTAMP default NULL,
				end TIMESTAMP default NULL,
				reason varchar(255) default NULL,
				PRIMARY KEY (id)
			)");
            $this->set_version("ext_ipban_version", 1);
        }

        if ($this->get_version("ext_ipban_version") == 1) {
            $database->execute("ALTER TABLE bans ADD COLUMN banner_id INTEGER NOT NULL AFTER id");
            $this->set_version("ext_ipban_version", 2);
        }

        if ($this->get_version("ext_ipban_version") == 2) {
            $database->execute("ALTER TABLE bans DROP COLUMN date");
            $database->execute("ALTER TABLE bans CHANGE ip ip CHAR(20) NOT NULL");
            $database->execute("ALTER TABLE bans CHANGE reason reason TEXT NOT NULL");
            $database->execute("CREATE INDEX bans__end ON bans(end)");
            $this->set_version("ext_ipban_version", 3);
        }

        if ($this->get_version("ext_ipban_version") == 3) {
            $database->execute("ALTER TABLE bans CHANGE end old_end DATE NOT NULL");
            $database->execute("ALTER TABLE bans ADD COLUMN end INTEGER");
            $database->execute("UPDATE bans SET end = UNIX_TIMESTAMP(old_end)");
            $database->execute("ALTER TABLE bans DROP COLUMN old_end");
            $database->execute("CREATE INDEX bans__end ON bans(end)");
            $this->set_version("ext_ipban_version", 4);
        }

        if ($this->get_version("ext_ipban_version") == 4) {
            $database->execute("ALTER TABLE bans CHANGE end end_timestamp INTEGER");
            $this->set_version("ext_ipban_version", 5);
        }

        if ($this->get_version("ext_ipban_version") == 5) {
            $database->execute("ALTER TABLE bans CHANGE ip ip VARCHAR(15)");
            $this->set_version("ext_ipban_version", 6);
        }

        if ($this->get_version("ext_ipban_version") == 6) {
            $database->Execute("ALTER TABLE bans ADD FOREIGN KEY (banner_id) REFERENCES users(id) ON DELETE CASCADE");
            $this->set_version("ext_ipban_version", 7);
        }

        if ($this->get_version("ext_ipban_version") == 7) {
            $database->execute($database->scoreql_to_sql("ALTER TABLE bans CHANGE ip ip SCORE_INET"));
            $database->execute("ALTER TABLE bans ADD COLUMN added TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
            $this->set_version("ext_ipban_version", 8);
        }

        if ($this->get_version("ext_ipban_version") == 8) {
            $database->execute("ALTER TABLE bans ADD COLUMN mode VARCHAR(16) NOT NULL DEFAULT 'block'");
            $this->set_version("ext_ipban_version", 9);
        }

        if ($this->get_version("ext_ipban_version") == 9) {
            $database->execute("ALTER TABLE bans ADD COLUMN expires TIMESTAMP DEFAULT NULL");
            $database->execute("UPDATE bans SET expires = to_date('1970/01/01', 'YYYY/MM/DD') + (end_timestamp * interval '1 seconds')");
            $database->execute("ALTER TABLE bans DROP COLUMN end_timestamp");
            $database->execute("CREATE INDEX bans__expires ON bans(expires)");
            $this->set_version("ext_ipban_version", 10);
        }
    }

    private function check_ip_ban()
    {
        $remote = $_SERVER['REMOTE_ADDR'];
        list($ips, $networks) = $this->get_active_bans_grouped();
        if (isset($ips[$remote])) {
            $this->block($ips[$remote]);  // never returns
        }
        foreach ($networks as $range => $ban_id) {
            if (ip_in_range($remote, $range)) {
                $this->block($ban_id);  // never returns
            }
        }
    }

    private function block(int $ban_id)
    {
        global $config, $database, $user, $page, $_shm_user_classes;

        $row = $database->get_row("SELECT * FROM bans WHERE id=:id", ["id"=>$ban_id]);

        $msg = $config->get_string("ipban_message");
        $msg = str_replace('$IP', $row["ip"], $msg);
        $msg = str_replace('$DATE', $row['expires'], $msg);
        $msg = str_replace('$ADMIN', User::by_id($row['banner_id'])->name, $msg);
        $msg = str_replace('$REASON', $row['reason'], $msg);
        $contact_link = contact_link();
        if (!empty($contact_link)) {
            $msg = str_replace('$CONTACT', "<a href='$contact_link'>Contact the staff (be sure to include this message)</a>", $msg);
        } else {
            $msg = str_replace('$CONTACT', "", $msg);
        }

        if($row["mode"] == "ghost") {
            $page->add_block(new Block(null, $msg, "main", 0));
            $user->class = $_shm_user_classes["ghost"];
        } else {
            header("HTTP/1.0 403 Forbidden");
            print "$msg";
            exit;
        }
    }

    // returns [ips, nets]
    private function get_active_bans_grouped()
    {
        global $cache, $database;

        $cached = $cache->get("ip_to_ban_id_grouped");
        if ($cached) {
            return $cached;
        }

        $rows = $database->get_pairs("
            SELECT ip, id
            FROM bans
            WHERE ((expires > CURRENT_TIMESTAMP) OR (expires IS NULL))
        ");

        $ips = []; # "0.0.0.0" => 123;
        $nets = []; # "0.0.0.0/32" => 456;
        foreach ($rows as $ip => $id) {
            if (strstr($ip, '/')) {
                $nets[$ip] = $id;
            } else {
                $ips[$ip] = $id;
            }
        }

        $sorted = [$ips, $nets];
        $cache->set("ip_to_ban_id_grouped", $sorted, 600);
        return $sorted;
    }
}
