<?php declare(strict_types=1);

require_once "mime_map.php";
require_once "file_extension.php";
require_once "mime_type.php";

class MimeSystem extends Extension
{
    /** @var MimeSystemTheme */
    protected $theme;

    const VERSION = "ext_mime_version";

    public function onParseLinkTemplate(ParseLinkTemplateEvent $event)
    {
        $event->replace('$ext', $event->image->get_ext());
        $event->replace('$mime', $event->image->get_mime());
    }


    public function onDatabaseUpgrade(DatabaseUpgradeEvent $event)
    {
        global $database;

        // These upgrades are primarily for initializing mime types on upgrade, and for adjusting mime types whenever an
        // adjustment needs to be made to the mime types.

        if ($this->get_version(self::VERSION) < 1) {
            if ($database->transaction) {
                // Each of these commands could hit a lot of data, combining
                // them into one big transaction would not be a good idea.
                $database->commit();
            }
            $database->set_timeout(300000); // These updates can take a little bit

            $extensions = $database->get_col_iterable("SELECT DISTINCT ext FROM images");

            foreach ($extensions as $ext) {
                $mime = MimeType::get_for_extension($ext);

                if (empty($mime) || $mime===MimeType::OCTET_STREAM) {
                    throw new SCoreException("Unknown extension: $ext");
                }

                $normalized_extension = FileExtension::get_for_mime($mime);

                $database->execute(
                    $database->scoreql_to_sql(
                        "UPDATE images SET mime = :mime, ext = :new_ext WHERE ext = :ext"
                    ),
                    ["mime" => $mime, "new_ext" => $normalized_extension, "ext" => $ext]
                );
            }

            $this->set_version(self::VERSION, 1);
        }
    }

    public function onHelpPageBuilding(HelpPageBuildingEvent $event)
    {
        if ($event->key===HelpPages::SEARCH) {
            $block = new Block();
            $block->header = "File Types";
            $block->body = $this->theme->get_help_html();
            $event->add_block($block);
        }
    }

    public function onSearchTermParse(SearchTermParseEvent $event)
    {
        if (is_null($event->term)) {
            return;
        }

        $matches = [];
        // check for tags first as tag based searches are more common.
        if (preg_match("/^ext[=|:]([a-zA-Z0-9]+)$/i", $event->term, $matches)) {
            $ext = strtolower($matches[1]);
            $event->add_querylet(new Querylet('images.ext = :ext', ["ext" => $ext]));
        } elseif (preg_match("/^mime[=|:](.+)$/i", $event->term, $matches)) {
            $mime = strtolower($matches[1]);
            $event->add_querylet(new Querylet("images.mime = :mime", ["mime"=>$mime]));
        }
    }
}