<?php
/**
 * UserAccessManager.php
 *
 * The UserAccessManager class file.
 *
 * PHP versions 5
 *
 * @category  UserAccessManager
 * @package   UserAccessManager
 * @author    Alexander Schneider <alexanderschneider85@googlemail.com>
 * @copyright 2008-2016 Alexander Schneider
 * @license   http://www.gnu.org/licenses/gpl-2.0.html  GNU General Public License, version 2
 * @version   SVN: $Id$
 * @link      http://wordpress.org/extend/plugins/user-access-manager/
 */

namespace UserAccessManager\Service;

use UserAccessManager\AccessHandler\AccessHandler;
use UserAccessManager\Config\Config;
use UserAccessManager\Database\Database;
use UserAccessManager\FileHandler\FileHandler;
use UserAccessManager\FileHandler\FileProtectionFactory;
use UserAccessManager\ObjectHandler\ObjectHandler;
use UserAccessManager\UserGroup\UserGroup;
use UserAccessManager\Util\Util;
use UserAccessManager\Wrapper\Wordpress;

/**
 * The user user access manager class.
 *
 * @category UserAccessManager
 * @package  UserAccessManager
 * @author   Alexander Schneider <alexanderschneider85@gmail.com>
 * @license  http://www.gnu.org/licenses/gpl-2.0.html  GNU General Public License, version 2
 * @link     http://wordpress.org/extend/plugins/user-access-manager/
 */
class UserAccessManager
{
    const VERSION = '1.2.14';
    const DB_VERSION = '1.4';

    const USER_OBJECT_TYPE = 'user';
    const POST_OBJECT_TYPE = 'post';
    const PAGE_OBJECT_TYPE = 'page';
    const TERM_OBJECT_TYPE = 'term';
    const ROLE_OBJECT_TYPE = 'role';
    const ATTACHMENT_OBJECT_TYPE = 'attachment';

    /**
     * names of style and script handles
     */
    const HANDLE_STYLE_ADMIN = 'UserAccessManagerAdmin';
    const HANDLE_STYLE_LOGIN_FORM = 'UserAccessManagerLoginForm';
    const HANDLE_SCRIPT_ADMIN = 'UserAccessManagerFunctions';

    /**
     * @var Wordpress
     */
    protected $_oWrapper;

    /**
     * @var Config
     */
    protected $_oConfig;

    /**
     * @var Database
     */
    protected $_oDatabase;

    /**
     * @var ObjectHandler
     */
    protected $_oObjectHandler;

    /**
     * @var AccessHandler
     */
    protected $_oAccessHandler;

    /**
     * @var FileHandler
     */
    protected $_oFileHandler;

    /**
     * @var Util
     */
    protected $_oUtil;

    /**
     * @var FileProtectionFactory
     */
    protected $_oFileProtectionFactory;

    protected $_aPostUrls = array();

    /**
     * UserAccessManager constructor.
     *
     * @param Wordpress             $oWrapper
     * @param Config                $oConfig
     * @param Database              $oDatabase
     * @param ObjectHandler         $oObjectHandler
     * @param AccessHandler         $oAccessHandler
     * @param FileHandler           $oFileHandler
     * @param Util                  $oUtil
     * @param FileProtectionFactory $oFileProtectionFactory
     */
    public function __construct(
        Wordpress $oWrapper,
        Config $oConfig,
        Database $oDatabase,
        ObjectHandler $oObjectHandler,
        AccessHandler $oAccessHandler,
        FileHandler $oFileHandler,
        Util $oUtil,
        FileProtectionFactory $oFileProtectionFactory
    )
    {
        $this->_oWrapper = $oWrapper;
        $this->_oConfig = $oConfig;
        $this->_oDatabase = $oDatabase;
        $this->_oObjectHandler = $oObjectHandler;
        $this->_oAccessHandler = $oAccessHandler;
        $this->_oUtil = $oUtil;
        $this->_oFileHandler = $oFileHandler;
        $this->_oFileProtectionFactory = $oFileProtectionFactory;
        $this->_oWrapper->doAction('uam_init', $this);
    }

    /**
     * Returns all blog of the network.
     *
     * @return array()
     */
    protected function _getBlogIds()
    {
        $aBlogIds = array();

        if ($this->_oWrapper->isMultiSite()) {
            $aBlogIds = $this->_oDatabase->getColumn(
                "SELECT blog_id
                FROM ".$this->_oDatabase->getBlogsTable()
            );
        }

        return $aBlogIds;
    }

    /**
     * Installs the user access manager.
     *
     * @param bool $blNetworkWide
     */
    public function install($blNetworkWide = false)
    {
        $aBlogIds = $this->_getBlogIds();

        if ($blNetworkWide === true) {
            $iCurrentBlogId = $this->_oDatabase->getCurrentBlogId();

            foreach ($aBlogIds as $iBlogId) {
                $this->_oWrapper->switchToBlog($iBlogId);
                $this->_installUam();
            }

            $this->_oWrapper->switchToBlog($iCurrentBlogId);
        } else {
            $this->_installUam();
        }
    }

    /**
     * Creates the needed tables at the database and adds the options
     */
    protected function _installUam()
    {
        include_once ABSPATH.'wp-admin/includes/upgrade.php';

        $sCharsetCollate = $this->_oDatabase->getCharset();
        $sDbAccessGroupTable = $this->_oDatabase->getUserGroupTable();

        $sDbUserGroup = $this->_oDatabase->getVariable(
            "SHOW TABLES 
            LIKE '{$sDbAccessGroupTable}'"
        );

        if ($sDbUserGroup !== $sDbAccessGroupTable) {
            $this->_oDatabase->dbDelta(
                "CREATE TABLE {$sDbAccessGroupTable} (
                    ID int(11) NOT NULL auto_increment,
                    groupname tinytext NOT NULL,
                    groupdesc text NOT NULL,
                    read_access tinytext NOT NULL,
                    write_access tinytext NOT NULL,
                    ip_range mediumtext NULL,
                    PRIMARY KEY (ID)
                ) {$sCharsetCollate};"
            );
        }

        $sDbAccessGroupToObjectTable = $this->_oDatabase->getUserGroupToObjectTable();

        $sDbAccessGroupToObject = $this->_oDatabase->getVariable(
            "SHOW TABLES 
            LIKE '".$sDbAccessGroupToObjectTable."'"
        );

        if ($sDbAccessGroupToObject !== $sDbAccessGroupToObjectTable) {
            $this->_oDatabase->dbDelta(
                "CREATE TABLE {$sDbAccessGroupToObjectTable} (
                    object_id VARCHAR(64) NOT NULL,
                    object_type varchar(64) NOT NULL,
                    group_id int(11) NOT NULL,
                    PRIMARY KEY (object_id,object_type,group_id)
                ) {$sCharsetCollate};"
            );
        }

        $this->_oWrapper->addOption('uam_db_version', self::DB_VERSION);
    }

    /**
     * Checks if a database update is necessary.
     *
     * @return boolean
     */
    public function isDatabaseUpdateNecessary()
    {
        $aBlogIds = $this->_getBlogIds();

        if ($aBlogIds !== array()
            && $this->_oWrapper->isSuperAdmin()
        ) {
            foreach ($aBlogIds as $iBlogId) {
                $sTable = $this->_oDatabase->getBlogPrefix($iBlogId).'options';
                $sSelect = "SELECT option_value FROM {$sTable} WHERE option_name = %s LIMIT 1";
                $sSelect = $this->_oDatabase->prepare($sSelect, 'uam_db_version');
                $sCurrentDbVersion = $this->_oDatabase->getVariable($sSelect);

                if (version_compare($sCurrentDbVersion, self::DB_VERSION, '<')) {
                    return true;
                }
            }
        }

        $sCurrentDbVersion = get_option('uam_db_version');
        return version_compare($sCurrentDbVersion, self::DB_VERSION, '<');
    }

    /**
     * Updates the user access manager if an old version was installed.
     *
     * @param boolean $blNetworkWide If true update network wide
     */
    public function update($blNetworkWide)
    {
        $aBlogIds = $this->_getBlogIds();

        if ($blNetworkWide
            && $aBlogIds !== array()
        ) {
            $iCurrentBlogId = $this->_oDatabase->getCurrentBlogId();

            foreach ($aBlogIds as $iBlogId) {
                $this->_oWrapper->switchToBlog($iBlogId);
                $this->_installUam();
                $this->_updateUam();
            }

            $this->_oWrapper->switchToBlog($iCurrentBlogId);
        } else {
            $this->_updateUam();
        }
    }

    /**
     * Updates the user access manager if an old version was installed.
     */
    protected function _updateUam()
    {
        $sCurrentDbVersion = $this->_oWrapper->getOption('uam_db_version');

        if (empty($sCurrentDbVersion)) {
            $this->install();
        }

        $sUamVersion = $this->_oWrapper->getOption('uam_version');

        if (!$sUamVersion || version_compare($sUamVersion, "1.0", '<')) {
            $this->_oWrapper->deleteOption('allow_comments_locked');
        }

        $sDbAccessGroup = $this->_oDatabase->getUserGroupTable();

        $sDbUserGroup = $this->_oDatabase->getVariable(
            "SHOW TABLES 
            LIKE '".$sDbAccessGroup."'"
        );

        if (version_compare($sCurrentDbVersion, self::DB_VERSION, '<')) {
            $sPrefix = $this->_oDatabase->getPrefix();
            $sCharsetCollate = $this->_oDatabase->getCharset();

            if (version_compare($sCurrentDbVersion, '1.0', '<=')) {
                if ($sDbUserGroup == $sDbAccessGroup) {
                    $this->_oDatabase->query(
                        "ALTER TABLE ".$sDbAccessGroup."
                        ADD read_access TINYTEXT NOT NULL DEFAULT '', 
                        ADD write_access TINYTEXT NOT NULL DEFAULT '', 
                        ADD ip_range MEDIUMTEXT NULL DEFAULT ''"
                    );

                    $this->_oDatabase->query(
                        "UPDATE ".$sDbAccessGroup."
                        SET read_access = 'group', 
                            write_access = 'group'"
                    );

                    $sDbIpRange = $this->_oDatabase->getVariable(
                        "SHOW columns 
                        FROM ".$sDbAccessGroup."
                        LIKE 'ip_range'"
                    );

                    if ($sDbIpRange != 'ip_range') {
                        $this->_oDatabase->query(
                            "ALTER TABLE ".$sDbAccessGroup."
                            ADD ip_range MEDIUMTEXT NULL DEFAULT ''"
                        );
                    }
                }

                $sDbAccessGroupToObject = $sPrefix.'uam_accessgroup_to_object';
                $sDbAccessGroupToPost = $sPrefix.'uam_accessgroup_to_post';
                $sDbAccessGroupToUser = $sPrefix.'uam_accessgroup_to_user';
                $sDbAccessGroupToCategory = $sPrefix.'uam_accessgroup_to_category';
                $sDbAccessGroupToRole = $sPrefix.'uam_accessgroup_to_role';

                $this->_oDatabase->query(
                    "ALTER TABLE '{$sDbAccessGroupToObject}'
                    CHANGE 'object_id' 'object_id' VARCHAR(64)
                    ".$sCharsetCollate
                );

                $aObjectTypes = $this->_oObjectHandler->getObjectTypes();

                foreach ($aObjectTypes as $sObjectType) {
                    $sAddition = '';

                    if ($this->_oObjectHandler->isPostableType($sObjectType)) {
                        $sDbIdName = 'post_id';
                        $sDatabase = $sDbAccessGroupToPost.', '.$this->_oDatabase->getPostsTable();
                        $sAddition = " WHERE post_id = ID
                            AND post_type = '".$sObjectType."'";
                    } elseif ($sObjectType == 'category') {
                        $sDbIdName = 'category_id';
                        $sDatabase = $sDbAccessGroupToCategory;
                    } elseif ($sObjectType == 'user') {
                        $sDbIdName = 'user_id';
                        $sDatabase = $sDbAccessGroupToUser;
                    } elseif ($sObjectType == 'role') {
                        $sDbIdName = 'role_name';
                        $sDatabase = $sDbAccessGroupToRole;
                    } else {
                        continue;
                    }

                    $sFullDatabase = $sDatabase.$sAddition;

                    $sSql = "SELECT {$sDbIdName} as id, group_id as groupId
                        FROM {$sFullDatabase}";

                    $aDbObjects = $this->_oDatabase->getResults($sSql);

                    foreach ($aDbObjects as $oDbObject) {
                        $this->_oDatabase->insert(
                            $sDbAccessGroupToObject,
                            array(
                                'group_id' => $oDbObject->groupId,
                                'object_id' => $oDbObject->id,
                                'object_type' => $sObjectType,
                            ),
                            array(
                                '%d',
                                '%d',
                                '%s',
                            )
                        );
                    }
                }

                $this->_oDatabase->query(
                    "DROP TABLE {$sDbAccessGroupToPost},
                        {$sDbAccessGroupToUser},
                        {$sDbAccessGroupToCategory},
                        {$sDbAccessGroupToRole}"
                );
            }

            if (version_compare($sCurrentDbVersion, '1.2', '<=')) {
                $sDbAccessGroupToObject = $this->_oDatabase->getUserGroupToObjectTable();

                $sSql = "
                    ALTER TABLE `{$sDbAccessGroupToObject}`
                    CHANGE `object_id` `object_id` VARCHAR(64) NOT NULL,
                    CHANGE `object_type` `object_type` VARCHAR(64) NOT NULL";

                $this->_oDatabase->query($sSql);
            }

            if (version_compare($sCurrentDbVersion, '1.3', '<=')) {
                $sDbAccessGroupToObject = $this->_oDatabase->getUserGroupToObjectTable();
                $sTermType = UserAccessManager::TERM_OBJECT_TYPE;
                $this->_oDatabase->update(
                    $sDbAccessGroupToObject,
                    array(
                        'object_type' => $sTermType,
                    ),
                    array(
                        'object_type' => 'category',
                    )
                );
            }

            update_option('uam_db_version', self::DB_VERSION);
        }
    }

    /**
     * Clean up wordpress if the plugin will be uninstalled.
     */
    public function uninstall()
    {
        $this->_oDatabase->query(
            "DROP TABLE {$this->_oDatabase->getUserGroupTable()}, 
              {$this->_oDatabase->getUserGroupToObjectTable()}"
        );

        $this->_oWrapper->deleteOption(Config::ADMIN_OPTIONS_NAME);
        $this->_oWrapper->deleteOption('uam_version');
        $this->_oWrapper->deleteOption('uam_db_version');
        $this->deleteFileProtectionFiles();
    }

    /**
     * Remove the htaccess file if the plugin is deactivated.
     */
    public function deactivate()
    {
        $this->deleteFileProtectionFiles();
    }

    /**
     * Returns the upload dirctory.
     *
     * @return null|string
     */
    protected function _getUploadDirectory()
    {
        $aWordpressUploadDir = $this->_oWrapper->getUploadDir();

        if (empty($aWordpressUploadDir['error'])) {
            return $aWordpressUploadDir['basedir'].DIRECTORY_SEPARATOR;
        }

        return null;
    }

    /**
     * Creates a protection file.
     *
     * @param string $sDir        The destination directory.
     * @param string $sObjectType The object type.
     */
    public function createFileProtection($sDir = null, $sObjectType = null)
    {
        $sDir = ($sDir === null) ? $this->_getUploadDirectory() : $sDir;

        if ($sDir !== null) {
            if ($this->_oWrapper->isNginx() === true) {
                $this->_oFileProtectionFactory->createNginxFileProtection()->create($sDir, $sObjectType);
            } else {
                $this->_oFileProtectionFactory->createApacheFileProtection()->create($sDir, $sObjectType);
            }
        }
    }


    /**
     * Deletes the protection files.
     *
     * @param string $sDir The destination directory.
     */
    public function deleteFileProtectionFiles($sDir = null)
    {
        $sDir = ($sDir === null) ? $this->_getUploadDirectory() : $sDir;

        if ($sDir !== null) {
            if ($this->_oWrapper->isNginx() === true) {
                $this->_oFileProtectionFactory->createNginxFileProtection()->delete($sDir);
            } else {
                $this->_oFileProtectionFactory->createApacheFileProtection()->delete($sDir);
            }
        }
    }

    /**
     * Returns the content of the excluded php file.
     *
     * @param string  $sFileName   The file name
     * @param integer $iObjectId   The _iId if needed.
     * @param string  $sObjectType The object type if needed.
     *
     * @return string
     */
    public function getIncludeContents($sFileName, $iObjectId = null, $sObjectType = null)
    {
        if (is_file($sFileName)) {
            ob_start();
            include $sFileName;
            $sContents = ob_get_contents();
            ob_end_clean();

            return $sContents;
        }

        return '';
    }


    /*
     * Functions for the admin panel content.
     */

    /**
     * Register styles and scripts with handle for admin panel.
     */
    protected function registerAdminStylesAndScripts()
    {
        wp_register_style(
            self::HANDLE_STYLE_ADMIN,
            UAM_URLPATH.'css/uamAdmin.css',
            array(),
            self::VERSION,
            'screen'
        );
        
        wp_register_script(
            self::HANDLE_SCRIPT_ADMIN,
            UAM_URLPATH.'js/functions.js',
            array('jquery'),
            self::VERSION
        );
    }

    /**
     * The function for the admin_enqueue_scripts action for styles and scripts.
     *
     * @param string $sHook
     */
    public function enqueueAdminStylesAndScripts($sHook)
    {
        $this->registerAdminStylesAndScripts();
        wp_enqueue_style(self::HANDLE_STYLE_ADMIN);

        if ($sHook == 'uam_page_uam_settings') {
            wp_enqueue_script(self::HANDLE_SCRIPT_ADMIN);
        }
    }

    /**
     * Functions for other content.
     */

    /**
     * Register all other styles.
     */
    protected function registerStylesAndScripts()
    {
        wp_register_style(
            self::HANDLE_STYLE_LOGIN_FORM,
            UAM_URLPATH.'css/uamLoginForm.css',
            array(),
            self::VERSION,
            'screen'
        );
    }

    /**
     * The function for the wp_enqueue_scripts action.
     */
    public function enqueueStylesAndScripts()
    {
        $this->registerStylesAndScripts();
        wp_enqueue_style(self::HANDLE_STYLE_LOGIN_FORM);
    }
    
    /**
     * Prints the admin page.
     */
    public function printAdminPage()
    {
        if (isset($_GET['page'])) {
            $sAdminPage = $_GET['page'];

            if ($sAdminPage == 'uam_settings') {
                include UAM_REALPATH.'tpl/adminSettings.php';
            } elseif ($sAdminPage == 'uam_usergroup') {
                include UAM_REALPATH.'tpl/adminGroup.php';
            } elseif ($sAdminPage == 'uam_setup') {
                include UAM_REALPATH.'tpl/adminSetup.php';
            } elseif ($sAdminPage == 'uam_about') {
                include UAM_REALPATH.'tpl/about.php';
            }
        }
    }

    /**
     * Shows the error if the user has no rights to edit the content.
     */
    public function noRightsToEditContent()
    {
        $blNoRights = false;

        if (isset($_GET['post']) && is_numeric($_GET['post'])) {
            $oPost = $this->_oObjectHandler->getPost($_GET['post']);
            $blNoRights = !$this->_oAccessHandler->checkObjectAccess($oPost->post_type, $oPost->ID);
        }

        if (isset($_GET['attachment_id']) && is_numeric($_GET['attachment_id']) && !$blNoRights) {
            $oPost = $this->_oObjectHandler->getPost($_GET['attachment_id']);
            $blNoRights = !$this->_oAccessHandler->checkObjectAccess($oPost->post_type, $oPost->ID);
        }

        if (isset($_GET['tag_ID']) && is_numeric($_GET['tag_ID']) && !$blNoRights) {
            $blNoRights = !$this->_oAccessHandler->checkObjectAccess(self::TERM_OBJECT_TYPE, $_GET['tag_ID']);
        }

        if ($blNoRights) {
            wp_die(TXT_UAM_NO_RIGHTS);
        }
    }

    /**
     * The function for the wp_dashboard_setup action.
     * Removes widgets to which a user should not have access.
     */
    public function setupAdminDashboard()
    {
        global $wp_meta_boxes;

        if (!$this->_oAccessHandler->checkUserAccess('manage_user_groups')) {
            unset($wp_meta_boxes['dashboard']['normal']['core']['dashboard_recent_comments']);
        }
    }

    /**
     * The function for the update_option_permalink_structure action.
     */
    public function updatePermalink()
    {
        $this->createFileProtection();
    }


    /*
     * Meta functions
     */

    /**
     * Saves the object data to the database.
     *
     * @param string         $sObjectType The object type.
     * @param integer        $iObjectId   The _iId of the object.
     * @param UserGroup[]    $aUserGroups The new usergroups for the object.
     */
    protected function _saveObjectData($sObjectType, $iObjectId, $aUserGroups = null)
    {
        $oUamAccessHandler = $this->_oAccessHandler;
        $aFormData = array();

        if (isset($_POST['uam_update_groups'])) {
            $aFormData = $_POST;
        } elseif (isset($_GET['uam_update_groups'])) {
            $aFormData = $_GET;
        }

        if (isset($aFormData['uam_update_groups'])
            && ($this->_oAccessHandler->checkUserAccess('manage_user_groups')
                || $this->_oConfig->authorsCanAddPostsToGroups() === true)
        ) {
            if ($aUserGroups === null) {
                $aUserGroups = (isset($aFormData['uam_usergroups']) && is_array($aFormData['uam_usergroups']))
                    ? $aFormData['uam_usergroups'] : array();
            }

            $aAddUserGroups = array_flip($aUserGroups);
            $aRemoveUserGroups = $this->_oAccessHandler->getUserGroupsForObject($sObjectType, $iObjectId);
            $aUamUserGroups = $this->_oAccessHandler->getUserGroups();
            $blRemoveOldAssignments = true;

            if (isset($aFormData['uam_bulk_type'])) {
                $sBulkType = $aFormData['uam_bulk_type'];

                if ($sBulkType === 'add') {
                    $blRemoveOldAssignments = false;
                } elseif ($sBulkType === 'remove') {
                    $aRemoveUserGroups = $aAddUserGroups;
                    $aAddUserGroups = array();
                }
            }

            foreach ($aUamUserGroups as $sGroupId => $oUamUserGroup) {
                if (isset($aRemoveUserGroups[$sGroupId])) {
                    $oUamUserGroup->removeObject($sObjectType, $iObjectId);
                }

                if (isset($aAddUserGroups[$sGroupId])) {
                    $oUamUserGroup->addObject($sObjectType, $iObjectId);
                }

                $oUamUserGroup->save($blRemoveOldAssignments);
            }
        }
    }

    /**
     * Removes the object data.
     *
     * @param string $sObjectType The object type.
     * @param int    $iId         The object id.
     */
    protected function _removeObjectData($sObjectType, $iId)
    {
        $this->_oDatabase->delete(
            $this->_oDatabase->getUserGroupToObjectTable(),
            array(
                'object_id' => $iId,
                'object_type' => $sObjectType,
            ),
            array(
                '%d',
                '%s',
            )
        );
    }


    /*
     * Functions for the post actions.
     */

    /**
     * The function for the manage_posts_columns and
     * the manage_pages_columns filter.
     *
     * @param array $aDefaults The table headers.
     *
     * @return array
     */
    public function addPostColumnsHeader($aDefaults)
    {
        $aDefaults['uam_access'] = __('Access', 'user-access-manager');
        return $aDefaults;
    }

    /**
     * The function for the manage_users_custom_column action.
     *
     * @param string  $sColumnName The column name.
     * @param integer $iId         The id.
     */
    public function addPostColumn($sColumnName, $iId)
    {
        if ($sColumnName == 'uam_access') {
            $oPost = $this->_oObjectHandler->getPost($iId);
            echo $this->getIncludeContents(UAM_REALPATH.'tpl/objectColumn.php', $oPost->ID, $oPost->post_type);
        }
    }

    /**
     * The function for the uma_post_access metabox.
     *
     * @param object $oPost The post.
     */
    public function editPostContent($oPost)
    {
        $iObjectId = $oPost->ID;
        include UAM_REALPATH.'tpl/postEditForm.php';
    }

    public function addBulkAction($sColumnName)
    {
        if ($sColumnName == 'uam_access') {
            include UAM_REALPATH.'tpl/bulkEditForm.php';
        }
    }

    /**
     * The function for the save_post action.
     *
     * @param mixed $mPostParam The post _iId or a array of a post.
     */
    public function savePostData($mPostParam)
    {
        if (is_array($mPostParam)) {
            $oPost = $this->_oObjectHandler->getPost($mPostParam['ID']);
        } else {
            $oPost = $this->_oObjectHandler->getPost($mPostParam);
        }

        $iPostId = $oPost->ID;
        $sPostType = $oPost->post_type;

        if ($sPostType == 'revision') {
            $iPostId = $oPost->post_parent;
            $oParentPost = $this->_oObjectHandler->getPost($iPostId);
            $sPostType = $oParentPost->post_type;
        }

        $this->_saveObjectData($sPostType, $iPostId);
    }

    /**
     * The function for the attachment_fields_to_save filter.
     * We have to use this because the attachment actions work
     * not in the way we need.
     *
     * @param object $oAttachment The attachment id.
     *
     * @return object
     */
    public function saveAttachmentData($oAttachment)
    {
        $this->savePostData($oAttachment['ID']);

        return $oAttachment;
    }

    /**
     * The function for the delete_post action.
     *
     * @param integer $iPostId The post id.
     */
    public function removePostData($iPostId)
    {
        $oPost = $this->_oObjectHandler->getPost($iPostId);

        $this->_oDatabase->delete(
            $this->_oDatabase->getUserGroupToObjectTable(),
            array(
                'object_id' => $iPostId,
                'object_type' => $oPost->post_type,
            ),
            array(
                '%d',
                '%s',
            )
        );
    }

    /**
     * The function for the media_meta action.
     *
     * @param string $sMeta The meta.
     * @param object $oPost The post.
     *
     * @return string
     */
    public function showMediaFile($sMeta = '', $oPost = null)
    {
        $sContent = $sMeta;
        $sContent .= '</td></tr><tr>';
        $sContent .= '<th class="label">';
        $sContent .= '<label>'.TXT_UAM_SET_UP_USERGROUPS.'</label>';
        $sContent .= '</th>';
        $sContent .= '<td class="field">';
        $sContent .= $this->getIncludeContents(UAM_REALPATH.'tpl/postEditForm.php', $oPost->ID);

        return $sContent;
    }


    /*
     * Functions for the user actions.
     */

    /**
     * The function for the manage_users_columns filter.
     *
     * @param array $aDefaults The table headers.
     *
     * @return array
     */
    public function addUserColumnsHeader($aDefaults)
    {
        $aDefaults['uam_access'] = __('uam user groups');
        return $aDefaults;
    }

    /**
     * The function for the manage_users_custom_column action.
     *
     * @param string  $sReturn     The normal return value.
     * @param string  $sColumnName The column name.
     * @param integer $iId         The id.
     *
     * @return string|null
     */
    public function addUserColumn($sReturn, $sColumnName, $iId)
    {
        if ($sColumnName == 'uam_access') {
            return $this->getIncludeContents(UAM_REALPATH.'tpl/userColumn.php', $iId, self::USER_OBJECT_TYPE);
        }

        return $sReturn;
    }

    /**
     * The function for the edit_user_profile action.
     */
    public function showUserProfile()
    {
        echo $this->getIncludeContents(UAM_REALPATH.'tpl/userProfileEditForm.php');
    }

    /**
     * The function for the profile_update action.
     *
     * @param integer $iUserId The user id.
     */
    public function saveUserData($iUserId)
    {
        $this->_saveObjectData(self::USER_OBJECT_TYPE, $iUserId);
    }

    /**
     * The function for the delete_user action.
     *
     * @param integer $iUserId The user id.
     */
    public function removeUserData($iUserId)
    {
        $this->_removeObjectData(self::USER_OBJECT_TYPE, $iUserId);
    }


    /*
     * Functions for the term actions.
     */

    /**
     * The function for the manage_categories_columns filter.
     *
     * @param array $aDefaults The table headers.
     *
     * @return array
     */
    public function addTermColumnsHeader($aDefaults)
    {
        $aDefaults['uam_access'] = __('Access', 'user-access-manager');
        return $aDefaults;
    }

    /**
     * The function for the manage_categories_custom_column action.
     *
     * @param string  $sContent    Content for the column. Multiple filter calls are possible, so we need to append.
     * @param string  $sColumnName The column name.
     * @param integer $iId         The id.
     *
     * @return string $sContent with content appended for 'uam_access' column
     */
    public function addTermColumn($sContent, $sColumnName, $iId)
    {
        if ($sColumnName == 'uam_access') {
            $sContent .= $this->getIncludeContents(UAM_REALPATH.'tpl/objectColumn.php', $iId, self::TERM_OBJECT_TYPE);
        }

        return $sContent;
    }

    /**
     * The function for the edit_{term}_form action.
     *
     * @param object $oTerm The term.
     */
    public function showTermEditForm($oTerm)
    {
        include UAM_REALPATH.'tpl/termEditForm.php';
    }

    /**
     * The function for the edit_{term} action.
     *
     * @param integer $iTermId The term id.
     */
    public function saveTermData($iTermId)
    {
        $this->_saveObjectData(self::TERM_OBJECT_TYPE, $iTermId);
    }

    /**
     * The function for the delete_{term} action.
     *
     * @param integer $iTermId The id of the term.
     */
    public function removeTermData($iTermId)
    {
        $this->_removeObjectData(self::TERM_OBJECT_TYPE, $iTermId);
    }


    /*
     * Functions for the pluggable object actions.
     */

    /**
     * The function for the pluggable save action.
     *
     * @param string  $sObjectType The name of the pluggable object.
     * @param integer $iObjectId   The pluggable object id.
     * @param array   $aUserGroups The user groups for the object.
     */
    public function savePlObjectData($sObjectType, $iObjectId, $aUserGroups = null)
    {
        $this->_saveObjectData($sObjectType, $iObjectId, $aUserGroups);
    }

    /**
     * The function for the pluggable remove action.
     *
     * @param string  $sObjectName The name of the pluggable object.
     * @param integer $iObjectId   The pluggable object id.
     */
    public function removePlObjectData($sObjectName, $iObjectId)
    {
        $this->_removeObjectData($sObjectName, $iObjectId);
    }

    /**
     * Returns the group selection form for pluggable objects.
     *
     * @param string  $sObjectType     The object type.
     * @param integer $iObjectId       The _iId of the object.
     * @param string  $aGroupsFormName The name of the form which contains the groups.
     *
     * @return string;
     */
    public function showPlGroupSelectionForm($sObjectType, $iObjectId, $aGroupsFormName = null)
    {
        $sFileName = UAM_REALPATH.'tpl/groupSelectionForm.php';
        $aUamUserGroups = $this->_oAccessHandler->getUserGroups();
        $aUserGroupsForObject = $this->_oAccessHandler->getUserGroupsForObject($sObjectType, $iObjectId);

        if (is_file($sFileName)) {
            ob_start();
            include $sFileName;
            $sContents = ob_get_contents();
            ob_end_clean();

            return $sContents;
        }

        return '';
    }

    /**
     * Returns the column for a pluggable object.
     *
     * @param string  $sObjectType The object type.
     * @param integer $iObjectId   The object id.
     *
     * @return string
     */
    public function getPlColumn($sObjectType, $iObjectId)
    {
        return $this->getIncludeContents(UAM_REALPATH.'tpl/objectColumn.php', $iObjectId, $sObjectType);
    }


    /*
     * Functions for the blog content.
     */

    /**
     * Manipulates the wordpress query object to filter content.
     *
     * @param object $oWpQuery The wordpress query object.
     */
    public function parseQuery($oWpQuery)
    {
        $aExcludedPosts = $this->_oAccessHandler->getExcludedPosts();
        $aAllExcludedPosts = $aExcludedPosts['all'];

        if (count($aAllExcludedPosts) > 0) {
            $oWpQuery->query_vars['post__not_in'] = array_merge(
                $oWpQuery->query_vars['post__not_in'],
                $aAllExcludedPosts
            );
        }
    }

    /**
     * Modifies the content of the post by the given settings.
     *
     * @param object $oPost The current post.
     *
     * @return object|null
     */
    protected function _processPost($oPost)
    {
        $sPostType = $oPost->post_type;

        if ($this->_oObjectHandler->isPostableType($sPostType)
            && $sPostType != UserAccessManager::POST_OBJECT_TYPE
            && $sPostType != UserAccessManager::PAGE_OBJECT_TYPE
        ) {
            $sPostType = UserAccessManager::POST_OBJECT_TYPE;
        } elseif ($sPostType != UserAccessManager::POST_OBJECT_TYPE
            && $sPostType != UserAccessManager::PAGE_OBJECT_TYPE
        ) {
            return $oPost;
        }

        if ($this->_oConfig->hideObjectType($sPostType) === true || $this->_oConfig->atAdminPanel()) {
            if ($this->_oAccessHandler->checkObjectAccess($oPost->post_type, $oPost->ID)) {
                $oPost->post_title .= $this->adminOutput($oPost->post_type, $oPost->ID);
                return $oPost;
            }
        } else {
            if (!$this->_oAccessHandler->checkObjectAccess($oPost->post_type, $oPost->ID)) {
                $oPost->isLocked = true;

                $sUamPostContent = $this->_oConfig->getObjectTypeContent($sPostType);
                $sUamPostContent = str_replace('[LOGIN_FORM]', $this->getLoginBarHtml(), $sUamPostContent);

                if ($this->_oConfig->hideObjectTypeTitle($sPostType) === true) {
                    $oPost->post_title = $this->_oConfig->getObjectTypeTitle($sPostType);
                }

                if ($this->_oConfig->hideObjectTypeComments($sPostType) === false) {
                    $oPost->comment_status = 'close';
                }

                if ($sPostType === 'post'
                    && $this->_oConfig->showPostContentBeforeMore() === true
                    && preg_match('/<!--more(.*?)?-->/', $oPost->post_content, $aMatches)
                ) {
                    $oPost->post_content = explode($aMatches[0], $oPost->post_content, 2);
                    $sUamPostContent = $oPost->post_content[0]." ".$sUamPostContent;
                }

                $oPost->post_content = stripslashes($sUamPostContent);
            }

            $oPost->post_title .= $this->adminOutput($oPost->post_type, $oPost->ID);

            return $oPost;
        }

        return null;
    }

    /**
     * The function for the the_posts filter.
     *
     * @param array $aPosts The posts.
     *
     * @return array
     */
    public function showPosts($aPosts = array())
    {
        $aShowPosts = array();
        
        if (!is_feed() || ($this->_oConfig->protectFeed() === true && is_feed())) { //TODO
            foreach ($aPosts as $iPostId) {
                if ($iPostId !== null) {
                    $oPost = $this->_processPost($iPostId);

                    if ($oPost !== null) {
                        $aShowPosts[] = $oPost;
                    }
                }
            }

            $aPosts = $aShowPosts;
        }

        return $aPosts;
    }

    /**
     * The function for the posts_where_paged filter.
     *
     * @param string $sSql The where sql statement.
     *
     * @return string
     */
    public function showPostSql($sSql)
    {
        $aExcludedPosts = $this->_oAccessHandler->getExcludedPosts();
        $aAllExcludedPosts = $aExcludedPosts['all'];

        if (count($aAllExcludedPosts) > 0) {
            $sExcludedPostsStr = implode(',', $aAllExcludedPosts);
            $sSql .= " AND {$this->_oDatabase->getPostsTable()}.ID NOT IN($sExcludedPostsStr) ";
        }

        return $sSql;
    }

    /**
     * Function for the wp_count_posts filter.
     *
     * @param \stdClass $oCounts
     * @param string   $sType
     *
     * @return \stdClass
     */
    public function showPostCount($oCounts, $sType)
    {
        $aExcludedPosts = $this->_oAccessHandler->getExcludedPosts();

        if (isset($aExcludedPosts[$sType])) {
            $oCounts->publish -= count($aExcludedPosts[$sType]);
        }

        return $oCounts;
    }

    /**
     * Sets the excluded terms as argument.
     *
     * @param array $aArguments
     *
     * @return array
     */
    public function getTermArguments($aArguments)
    {
        $aExclude = (isset($aArguments['exclude'])) ? $this->_oWrapper->parseIdList($aArguments['exclude']) : array();
        $aExcludedTerms = $this->_oAccessHandler->getExcludedTerms();

        if ($this->_oConfig->lockRecursive() === true) {
            $aTermTreeMap = $this->_oObjectHandler->getTermTreeMap();

            foreach ($aExcludedTerms as $sTermId) {
                if (isset($aTermTreeMap[$sTermId])) {
                    $aExcludedTerms = array_merge($aExcludedTerms, array_keys($aTermTreeMap[$sTermId]));
                }
            }
        }

        $aArguments['exclude'] = array_merge($aExclude, $aExcludedTerms);

        return $aArguments;
    }

    /**
     * The function for the wp_get_nav_menu_items filter.
     *
     * @param array $aItems The menu item.
     *
     * @return array
     */
    public function showCustomMenu($aItems)
    {
        $aShowItems = array();
        $aTaxonomies = $this->_oObjectHandler->getTaxonomies();

        foreach ($aItems as $oItem) {
            if ($oItem->object == UserAccessManager::POST_OBJECT_TYPE
                || $oItem->object == UserAccessManager::PAGE_OBJECT_TYPE
            ) {
                $oObject = $this->_oObjectHandler->getPost($oItem->object_id);

                if ($oObject !== null) {
                    $oPost = $this->_processPost($oObject);

                    if ($oPost !== null) {
                        if (isset($oPost->isLocked)) {
                            $oItem->title = $oPost->post_title;
                        }

                        $oItem->title .= $this->adminOutput($oItem->object, $oItem->object_id);
                        $aShowItems[] = $oItem;
                    }
                }
            } elseif (isset($aTaxonomies[$oItem->object])) {
                $oObject = $this->_oObjectHandler->getTerm($oItem->object_id);
                $oCategory = $this->_processTerm($oObject);

                if ($oCategory !== null && !$oCategory->isEmpty) {
                    $oItem->title .= $this->adminOutput($oItem->object, $oItem->object_id);
                    $aShowItems[] = $oItem;
                }
            } else {
                $aShowItems[] = $oItem;
            }
        }

        return $aShowItems;
    }

    /**
     * The function for the comments_array filter.
     *
     * @param array $aComments The comments.
     *
     * @return array
     */
    public function showComment($aComments = array())
    {
        $aShowComments = array();

        foreach ($aComments as $oComment) {
            $oPost = $this->_oObjectHandler->getPost($oComment->comment_post_ID);
            $sPostType = $oPost->post_type;

            if ($this->_oConfig->hideObjectTypeComments($sPostType) === true
                || $this->_oConfig->hideObjectType($sPostType) === true
                || $this->_oConfig->atAdminPanel()
            ) {
                if ($this->_oAccessHandler->checkObjectAccess($oPost->post_type, $oPost->ID)) {
                    $aShowComments[] = $oComment;
                }
            } else {
                if (!$this->_oAccessHandler->checkObjectAccess($oPost->post_type, $oPost->ID)) {
                    $oComment->comment_content = $this->_oConfig->getObjectTypeCommentContent($sPostType);
                }

                $aShowComments[] = $oComment;
            }
        }

        $aComments = $aShowComments;

        return $aComments;
    }

    /**
     * The function for the get_pages filter.
     *
     * @param array $aPages The pages.
     *
     * @return array
     */
    public function showPages($aPages = array())
    {
        $aShowPages = array();

        foreach ($aPages as $oPage) {
            if ($this->_oConfig->hidePage() === true
                || $this->_oConfig->atAdminPanel()
            ) {
                if ($this->_oAccessHandler->checkObjectAccess($oPage->post_type, $oPage->ID)) {
                    $oPage->post_title .= $this->adminOutput(
                        $oPage->post_type,
                        $oPage->ID
                    );
                    $aShowPages[] = $oPage;
                }
            } else {
                if (!$this->_oAccessHandler->checkObjectAccess($oPage->post_type, $oPage->ID)) {
                    if ($this->_oConfig->hidePageTitle() === true) {
                        $oPage->post_title = $this->_oConfig->getPageTitle();
                    }

                    $oPage->post_content = $this->_oConfig->getPageContent();
                }

                $oPage->post_title .= $this->adminOutput($oPage->post_type, $oPage->ID);
                $aShowPages[] = $oPage;
            }
        }

        $aPages = $aShowPages;

        return $aPages;
    }

    /**
     * Returns the post count for the term.
     *
     * @param int $iTermId
     *
     * @return int
     */
    protected function _getVisibleElementsCount($iTermId)
    {
        $iCount = 0;
        $aTermPostMap = $this->_oObjectHandler->getTermPostMap();

        if (isset($aTermPostMap[$iTermId])) {
            foreach ($aTermPostMap[$iTermId] as $iPostId => $sPostType) {
                if ($this->_oConfig->hideObjectType($sPostType) === false
                    || $this->_oAccessHandler->checkObjectAccess($sPostType, $iPostId)
                ) {
                    $iCount++;
                }
            }
        }

        return $iCount;
    }

    /**
     * Modifies the content of the term by the given settings.
     *
     * @param object $oTerm The current term.
     *
     * @return object|null
     */
    protected function _processTerm($oTerm)
    {
        if (is_object($oTerm) === false) {
            return $oTerm;
        }

        $oTerm->name .= $this->adminOutput(self::TERM_OBJECT_TYPE, $oTerm->term_id, $oTerm->name);

        $oTerm->isEmpty = false;

        if ($this->_oAccessHandler->checkObjectAccess(self::TERM_OBJECT_TYPE, $oTerm->term_id)) {
            if ($this->_oConfig->hidePost() === true || $this->_oConfig->hidePage() === true) {
                $iTermRequest = $oTerm->term_id;
                $oTerm->count = $this->_getVisibleElementsCount($iTermRequest);
                $iFullCount = $oTerm->count;

                if ($iFullCount <= 0) {
                    $aTermTreeMap = $this->_oObjectHandler->getTermTreeMap();

                    if (isset($aTermTreeMap[$iTermRequest])) {
                        foreach ($aTermTreeMap[$iTermRequest] as $iTermId => $sType) {
                            if ($oTerm->taxonomy === $sType) {
                                $iFullCount += $this->_getVisibleElementsCount($iTermId);

                                if ($iFullCount > 0) {
                                    break;
                                }
                            }
                        }
                    }
                }

                //For categories
                if ($iFullCount <= 0
                    && $this->_oConfig->atAdminPanel() === false
                    && $this->_oConfig->hideEmptyCategories() === true
                    && ($oTerm->taxonomy == 'term' || $oTerm->taxonomy == 'category')
                ) {
                    $oTerm->isEmpty = true;
                }

                if ($this->_oConfig->lockRecursive() === false) {
                    $oCurrentTerm = $oTerm;

                    while ($oCurrentTerm->parent != 0) {
                        $oCurrentTerm = $this->_oObjectHandler->getTerm($oCurrentTerm->parent);

                        if ($this->_oAccessHandler->checkObjectAccess(UserAccessManager::TERM_OBJECT_TYPE, $oCurrentTerm->term_id)) {
                            $oTerm->parent = $oCurrentTerm->term_id;
                            break;
                        }
                    }
                }
            }

            return $oTerm;
        }

        return null;
    }

    /**
     * The function for the get_ancestors filter.
     *
     * @param array  $aAncestors
     * @param int    $sObjectId
     * @param string $sObjectType
     * @param string $sResourceType
     *
     * @return array
     */
    public function showAncestors($aAncestors, $sObjectId, $sObjectType, $sResourceType)
    {
        if ($sResourceType === 'taxonomy') {
            foreach ($aAncestors as $sKey => $aAncestorId) {
                if (!$this->_oAccessHandler->checkObjectAccess(self::TERM_OBJECT_TYPE, $aAncestorId)) {
                    unset($aAncestors[$sKey]);
                }
            }
        }

        return $aAncestors;
    }

    /**
     * The function for the get_term filter.
     *
     * @param object $oTerm
     *
     * @return null|object
     */
    public function showTerm($oTerm)
    {
        return $this->_processTerm($oTerm);
    }

    /**
     * The function for the get_terms filter.
     *
     * @param array          $aTerms      The terms.
     * @param array          $aTaxonomies The taxonomies.
     * @param array          $aArgs       The given arguments.
     * @param \WP_Term_Query $oTermQuery  The term query.
     *
     * @return array
     */
    public function showTerms($aTerms = array(), $aTaxonomies = array(), $aArgs = array(), $oTermQuery = null)
    {
        $aShowTerms = array();

        foreach ($aTerms as $mTerm) {
            if (!is_object($mTerm) && is_numeric($mTerm)) {
                if ((int)$mTerm === 0) {
                    continue;
                }

                $mTerm = $this->_oObjectHandler->getTerm($mTerm);
            }

            $mTerm = $this->_processTerm($mTerm);

            if ($mTerm !== null && (!isset($mTerm->isEmpty) || !$mTerm->isEmpty)) {
                $aShowTerms[$mTerm->term_id] = $mTerm;
            }
        }

        foreach ($aTerms as $sKey => $mTerm) {
            if ($mTerm === null || is_object($mTerm) && !isset($aShowTerms[$mTerm->term_id])) {
                unset($aTerms[$sKey]);
            }
        }

        return $aTerms;
    }

    /**
     * The function for the get_previous_post_where and
     * the get_next_post_where filter.
     *
     * @param string $sSql The current sql string.
     *
     * @return string
     */
    public function showNextPreviousPost($sSql)
    {
        $aExcludedPosts = $this->_oAccessHandler->getExcludedPosts();
        $aAllExcludedPosts = $aExcludedPosts['all'];

        if (count($aAllExcludedPosts) > 0) {
            $sExcludedPosts = implode(',', $aAllExcludedPosts);
            $sSql .= " AND p.ID NOT IN({$sExcludedPosts}) ";
        }

        return $sSql;
    }

    /**
     * Returns the admin hint.
     *
     * @param string  $sObjectType The object type.
     * @param integer $iObjectId   The object id we want to check.
     * @param string  $sText       The text on which we want to append the hint.
     *
     * @return string
     */
    public function adminOutput($sObjectType, $iObjectId, $sText = null)
    {
        $sOutput = '';

        if ($this->_oConfig->atAdminPanel() === false
            && $this->_oConfig->blogAdminHint() === true
        ) {
            $sHintText = $this->_oConfig->getBlogAdminHintText();

            if ($sText !== null && $this->_oUtil->endsWith($sText, $sHintText)) {
                return $sOutput;
            }

            $oCurrentUser = $this->_oWrapper->getCurrentUser();

            if (!isset($oCurrentUser->user_level)) {
                return $sOutput;
            }

            if ($this->_oAccessHandler->userIsAdmin($oCurrentUser->ID)
                && count($this->_oAccessHandler->getUserGroupsForObject($sObjectType, $iObjectId)) > 0
            ) {
                $sOutput .= $sHintText;
            }
        }


        return $sOutput;
    }

    /**
     * The function for the edit_post_link filter.
     *
     * @param string  $sLink   The edit link.
     * @param integer $iPostId The _iId of the post.
     *
     * @return string
     */
    public function showGroupMembership($sLink, $iPostId)
    {
        $aGroups = $this->_oAccessHandler->getUserGroupsForObject(self::POST_OBJECT_TYPE, $iPostId);

        if (count($aGroups) > 0) {
            $sLink .= ' | '.TXT_UAM_ASSIGNED_GROUPS.': ';

            foreach ($aGroups as $oGroup) {
                $sLink .= htmlentities($oGroup->getGroupName()).', ';
            }

            $sLink = rtrim($sLink, ', ');
        }

        return $sLink;
    }

    /**
     * Returns the login bar.
     *
     * @return string
     */
    public function getLoginBarHtml()
    {
        if (!is_user_logged_in()) { //TODO
            return $this->getIncludeContents(UAM_REALPATH.'tpl/loginBar.php');
        }

        return '';
    }


    /*
     * Functions for the redirection and files.
     */

    /**
     * Redirects to a page or to content.
     *
     * @param string $sHeaders    The headers which are given from wordpress.
     * @param object $oPageParams The params of the current page.
     *
     * @return string
     */
    public function redirect($sHeaders, $oPageParams)
    {
        if (isset($_GET['uamgetfile']) && isset($_GET['uamfiletype'])) {
            $sFileUrl = $_GET['uamgetfile'];
            $sFileType = $_GET['uamfiletype'];
            $this->getFile($sFileType, $sFileUrl);
        } elseif (!$this->_oConfig->atAdminPanel() && $this->_oConfig->getRedirect() !== 'false') {
            $oObject = null;

            if (isset($oPageParams->query_vars['p'])) {
                $oObject = $this->_oObjectHandler->getPost($oPageParams->query_vars['p']);
                $oObjectType = $oObject->post_type;
                $iObjectId = $oObject->ID;
            } elseif (isset($oPageParams->query_vars['page_id'])) {
                $oObject = $this->_oObjectHandler->getPost($oPageParams->query_vars['page_id']);
                $oObjectType = $oObject->post_type;
                $iObjectId = $oObject->ID;
            } elseif (isset($oPageParams->query_vars['cat_id'])) {
                $oObject = $this->_oObjectHandler->getTerm($oPageParams->query_vars['cat_id']);
                $oObjectType = self::TERM_OBJECT_TYPE;
                $iObjectId = $oObject->term_id;
            } elseif (isset($oPageParams->query_vars['name'])) {
                $sPostableTypes = "'".implode("','", $this->_oObjectHandler->getPostableTypes())."'";

                $sQuery = $this->_oDatabase->prepare(
                    "SELECT ID
                    FROM {$this->_oDatabase->getPostsTable()}
                    WHERE post_name = %s
                      AND post_type IN ({$sPostableTypes})",
                    $oPageParams->query_vars['name']
                );

                $sObjectId = $this->_oDatabase->getVariable($sQuery);

                if ($sObjectId) {
                    $oObject = $this->_oObjectHandler->getPost($sObjectId);
                }

                if ($oObject !== null) {
                    $oObjectType = $oObject->post_type;
                    $iObjectId = $oObject->ID;
                }
            } elseif (isset($oPageParams->query_vars['pagename'])) {
                $oObject = get_page_by_path($oPageParams->query_vars['pagename']); //TODO

                if ($oObject !== null) {
                    $oObjectType = $oObject->post_type;
                    $iObjectId = $oObject->ID;
                }
            }

            if ($oObject !== null
                && isset($oObjectType)
                && isset($iObjectId)
                && !$this->_oAccessHandler->checkObjectAccess($oObjectType, $iObjectId)
            ) {
                $this->redirectUser($oObject);
            }
        }

        return $sHeaders;
    }

    /**
     * Redirects the user to his destination.
     *
     * @param object $oObject The current object we want to access.
     */
    public function redirectUser($oObject = null)
    {
        global $wp_query; // TODO

        $blPostToShow = false;
        $aPosts = $wp_query->get_posts();

        if ($oObject === null && isset($aPosts)) {
            foreach ($aPosts as $oPost) {
                if ($this->_oAccessHandler->checkObjectAccess($oPost->post_type, $oPost->ID)) {
                    $blPostToShow = true;
                    break;
                }
            }
        }

        if ($blPostToShow === false) {
            $sPermalink = null;

            if ($this->_oConfig->getRedirect() === 'custom_page') {
                $sRedirectCustomPage = $this->_oConfig->getRedirectCustomPage();
                $oPost = $this->_oObjectHandler->getPost($sRedirectCustomPage);
                $sUrl = $oPost->guid;
                $sPermalink = get_page_link($oPost);
            } elseif ($this->_oConfig->getRedirect() === 'custom_url') {
                $sUrl = $this->_oConfig->getRedirectCustomUrl();
            } else {
                $sUrl = home_url('/');
            }

            $sCurrentUrl = $this->_oUtil->getCurrentUrl();

            if ($sUrl != $sCurrentUrl && $sPermalink != $sCurrentUrl) {
                wp_redirect($sUrl); //TODO
                exit;
            }
        }
    }

    /**
     * Delivers the content of the requested file.
     *
     * @param string $sObjectType The type of the requested file.
     * @param string $sObjectUrl  The file url.
     *
     * @return null
     */
    public function getFile($sObjectType, $sObjectUrl)
    {
        $oObject = $this->_getFileSettingsByType($sObjectType, $sObjectUrl);

        if ($oObject === null) {
            return null;
        }

        $sFile = null;

        if ($this->_oAccessHandler->checkObjectAccess($oObject->type, $oObject->id)) {
            $sFile = $oObject->file;
        } elseif ($oObject->isImage) {
            $sFile = UAM_REALPATH.'gfx/noAccessPic.png';
        } else {
            $this->_oWrapper->wpDie(TXT_UAM_NO_RIGHTS);
        }

        $blIsImage = $oObject->isFile;

        $this->_oFileHandler->getFile($sFile, $blIsImage);
        return null;
    }

    /**
     * Returns the file object by the given type and url.
     *
     * @param string $sObjectType The type of the requested file.
     * @param string $sObjectUrl  The file url.
     *
     * @return object|null
     */
    protected function _getFileSettingsByType($sObjectType, $sObjectUrl)
    {
        $oObject = null;

        if ($sObjectType == UserAccessManager::ATTACHMENT_OBJECT_TYPE) {
            $aUploadDir = wp_upload_dir();
            $sUploadDir = str_replace(ABSPATH, '/', $aUploadDir['basedir']);
            $sRegex = '/.*'.str_replace('/', '\/', $sUploadDir).'\//i';
            $sCleanObjectUrl = preg_replace($sRegex, '', $sObjectUrl);
            $sUploadUrl = str_replace('/files', $sUploadDir, $aUploadDir['baseurl']);
            $sObjectUrl = $sUploadUrl.'/'.ltrim($sCleanObjectUrl, '/');
            $oPost = $this->_oObjectHandler->getPost($this->getPostIdByUrl($sObjectUrl));

            if ($oPost !== null
                && $oPost->post_type == UserAccessManager::ATTACHMENT_OBJECT_TYPE
            ) {
                $oObject = new \stdClass();
                $oObject->id = $oPost->ID;
                $oObject->isImage = wp_attachment_is_image($oPost->ID);
                $oObject->type = $sObjectType;
                $sMultiPath = str_replace('/files', $sUploadDir, $aUploadDir['baseurl']);
                $oObject->file = $aUploadDir['basedir'].str_replace($sMultiPath, '', $sObjectUrl);
            }
        } else {
            $aPlObject = $this->_oObjectHandler->getPlObject($sObjectType);

            if (isset($aPlObject) && isset($aPlObject['getFileObject'])) {
                $oObject = $aPlObject['reference']->{$aPlObject['getFileObject']}($sObjectUrl);
            }
        }

        return $oObject;
    }

    /**
     * Returns the url for a locked file.
     *
     * @param string  $sUrl The base url.
     * @param integer $iId  The _iId of the file.
     *
     * @return string
     */
    public function getFileUrl($sUrl, $iId)
    {
        if ($this->_oConfig->isPermalinksActive() === false && $this->_oConfig->lockFile() === true) {
            $oPost = $this->_oObjectHandler->getPost($iId);
            $aType = explode('/', $oPost->post_mime_type);
            $sType = $aType[1];
            $aFileTypes = explode(',', $this->_oConfig->getLockedFileTypes());

            if ($this->_oConfig->getLockedFileTypes() === 'all' || in_array($sType, $aFileTypes)) {
                $sUrl = $this->_oWrapper->getHomeUrl('/').'?uamfiletype=attachment&uamgetfile='.$sUrl;
            }
        }

        return $sUrl;
    }

    /**
     * Returns the post by the given url.
     *
     * @param string $sUrl The url of the post(attachment).
     *
     * @return object The post.
     */
    public function getPostIdByUrl($sUrl)
    {
        if (isset($this->_aPostUrls[$sUrl])) {
            return $this->_aPostUrls[$sUrl];
        }

        $this->_aPostUrls[$sUrl] = null;

        //Filter edit string
        $sNewUrl = preg_split("/-e[0-9]{1,}/", $sUrl);

        if (count($sNewUrl) == 2) {
            $sNewUrl = $sNewUrl[0].$sNewUrl[1];
        } else {
            $sNewUrl = $sNewUrl[0];
        }

        //Filter size
        $sNewUrl = preg_split("/-[0-9]{1,}x[0-9]{1,}/", $sNewUrl);

        if (count($sNewUrl) == 2) {
            $sNewUrl = $sNewUrl[0].$sNewUrl[1];
        } else {
            $sNewUrl = $sNewUrl[0];
        }

        $sSql = $this->_oDatabase->prepare(
            "SELECT ID
            FROM {$this->_oDatabase->getPostsTable()}
            WHERE guid = '%s'
            LIMIT 1",
            $sNewUrl
        );

        $oDbPost = $this->_oDatabase->getRow($sSql);

        if ($oDbPost) {
            $this->_aPostUrls[$sUrl] = $oDbPost->ID;
        }

        return $this->_aPostUrls[$sUrl];
    }

    /**
     * Caches the urls for the post for a later lookup.
     *
     * @param string $sUrl  The url of the post.
     * @param object $oPost The post object.
     *
     * @return string
     */
    public function cachePostLinks($sUrl, $oPost)
    {
        $this->_aPostUrls[$sUrl] = $oPost->ID;
        return $sUrl;
    }

    /**
     * Filter for Yoast SEO Plugin
     *
     * Hides the url from the site map if the user has no access
     *
     * @param string $sUrl    The url to check
     * @param string $sType   The object type
     * @param object $oObject The object
     *
     * @return false|string
     */
    function wpSeoUrl($sUrl, $sType, $oObject)
    {
        return ($this->_oAccessHandler->checkObjectAccess($sType, $oObject->ID)) ? $sUrl : false;
    }
}
