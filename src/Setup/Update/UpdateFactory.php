<?php
/**
 * UpdateFactory.php
 *
 * The UpdateFactory class file.
 *
 * PHP versions 5
 *
 * @author    Alexander Schneider <alexanderschneider85@gmail.com>
 * @copyright 2008-2017 Alexander Schneider
 * @license   http://www.gnu.org/licenses/gpl-2.0.html  GNU General Public License, version 2
 * @version   SVN: $id$
 * @link      http://wordpress.org/extend/plugins/user-access-manager/
 */
namespace UserAccessManager\Setup\Update;

use UserAccessManager\Database\Database;
use UserAccessManager\Object\ObjectHandler;

/**
 * Class UpdateFactory
 *
 * @package UserAccessManager\Setup\Update
 */
class UpdateFactory
{
    /**
     * @var Database
     */
    protected $database;

    /**
     * @var ObjectHandler
     */
    protected $objectHandler;

    /**
     * UpdateFactory constructor.
     *
     * @param Database      $database
     * @param ObjectHandler $objectHandler
     */
    public function __construct(Database $database, ObjectHandler $objectHandler)
    {
        $this->database = $database;
        $this->objectHandler = $objectHandler;
    }

    /**
     * Returns all available updates.
     *
     * @return UpdateInterface[]
     */
    public function getUpdates()
    {
        return [
            new Update1($this->database, $this->objectHandler),
            new Update2($this->database, $this->objectHandler),
            new Update3($this->database, $this->objectHandler),
            new Update4($this->database, $this->objectHandler),
            new Update5($this->database, $this->objectHandler),
            new Update6($this->database, $this->objectHandler)
        ];
    }
}