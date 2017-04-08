<?php
/**
 * PluggableObjectTest.php
 *
 * The PluggableObjectTest unit test class file.
 *
 * PHP versions 5
 *
 * @author    Alexander Schneider <alexanderschneider85@gmail.com>
 * @copyright 2008-2017 Alexander Schneider
 * @license   http://www.gnu.org/licenses/gpl-2.0.html  GNU General Public License, version 2
 * @version   SVN: $Id$
 * @link      http://wordpress.org/extend/plugins/user-access-manager/
 */
namespace UserAccessManager;

use UserAccessManager\ObjectHandler\ObjectHandler;

/**
 * Class UserAccessManagerTest
 *
 * @package UserAccessManager
 */
class UserAccessManagerTest extends UserAccessManagerTestCase
{
    /**
     * @group  unit
     * @covers \UserAccessManager\UserAccessManager::__construct()
     */
    public function testCanCreateInstance()
    {
        $oObjectHandler = new UserAccessManager(
            $this->getPhp(),
            $this->getWordpress(),
            $this->getConfig(),
            $this->getObjectHandler(),
            $this->getAccessHandler(),
            $this->getSetupHandler(),
            $this->getControllerFactory()
        );

        self::assertInstanceOf('\UserAccessManager\UserAccessManager', $oObjectHandler);
    }

    /**
     * @group  unit
     * @covers \UserAccessManager\UserAccessManager::registerAdminMenu()
     */
    public function testRegisterAdminMenu()
    {
        $oWordpress = $this->getWordpress();
        $oWordpress->expects($this->once())
            ->method('addMenuPage');
        $oWordpress->expects($this->exactly(4))
            ->method('addSubmenuPage');
        $oWordpress->expects($this->once())
            ->method('doAction');

        $oAccessHandler = $this->getAccessHandler();
        $oAccessHandler->expects($this->exactly(2))
            ->method('checkUserAccess')
            ->will($this->onConsecutiveCalls(false, true));

        $oControllerFactory = $this->getControllerFactory();
        $oControllerFactory->expects($this->once())
            ->method('createAdminUserGroupController');

        $oControllerFactory->expects($this->once())
            ->method('createAdminSettingsController');

        $oControllerFactory->expects($this->once())
            ->method('createAdminSetupController');

        $oControllerFactory->expects($this->once())
            ->method('createAdminAboutController');

        $oObjectHandler = new UserAccessManager(
            $this->getPhp(),
            $oWordpress,
            $this->getConfig(),
            $this->getObjectHandler(),
            $oAccessHandler,
            $this->getSetupHandler(),
            $oControllerFactory
        );

        $oObjectHandler->registerAdminMenu();
        $oObjectHandler->registerAdminMenu();
    }

    /**
     * @group  unit
     * @covers \UserAccessManager\UserAccessManager::registerAdminActionsAndFilters()
     */
    public function testRegisterAdminActionsAndFilters()
    {
        $oPhp = $this->getPhp();
        $oPhp->expects($this->exactly(3))
            ->method('iniGet')
            ->will($this->returnValue(true));

        $oWordpress = $this->getWordpress();
        $oWordpress->expects($this->exactly(57))
            ->method('addAction');

        $oWordpress->expects($this->exactly(16))
            ->method('addFilter');

        $oWordpress->expects($this->exactly(3))
            ->method('addMetaBox');

        $oConfig = $this->getConfig();
        $oConfig->expects($this->exactly(3))
            ->method('getDownloadType')
            ->will($this->onConsecutiveCalls(null, 'fopen', 'fopen'));

        $oConfig->expects($this->exactly(2))
            ->method('authorsCanAddPostsToGroups')
            ->will($this->onConsecutiveCalls(true, false));

        $oConfig->expects($this->exactly(6))
            ->method('lockFile')
            ->will($this->onConsecutiveCalls(false, false, false, true, true, true));


        $oObjectHandler = $this->getObjectHandler();
        $oObjectHandler->expects($this->exactly(3))
            ->method('getTaxonomies')
            ->will($this->returnValue(['a', 'b']));

        $oObjectHandler->expects($this->exactly(2))
            ->method('getPostTypes')
            ->will($this->returnValue(['a', ObjectHandler::ATTACHMENT_OBJECT_TYPE]));

        $oAccessHandler = $this->getAccessHandler();
        $oAccessHandler->expects($this->exactly(3))
            ->method('checkUserAccess')
            ->will($this->onConsecutiveCalls(true, false, false));

        $oSetupHandler = $this->getSetupHandler();
        $oSetupHandler->expects($this->exactly(3))
            ->method('isDatabaseUpdateNecessary')
            ->will($this->onConsecutiveCalls(false, true, false));

        $oAdminController = $this->createMock('UserAccessManager\Controller\AdminController');
        $oAdminController->expects($this->exactly(3))
            ->method('getRequestParameter')
            ->will($this->onConsecutiveCalls(null, 'c', 'c'));

        $oControllerFactory = $this->getControllerFactory();
        $oControllerFactory->expects($this->exactly(3))
            ->method('createAdminController')
            ->will($this->returnValue($oAdminController));

        $oControllerFactory->expects($this->exactly(3))
            ->method('createAdminObjectController')
            ->will($this->returnCallback(function () {
                $oAdminObjectController = $this->createMock('UserAccessManager\Controller\AdminObjectController');
                $oAdminObjectController->expects($this->any())
                    ->method('checkRightsToEditContent');

                $oAdminObjectController->expects($this->any())
                    ->method('getRequestParameter')
                    ->will($this->returnValue('c'));

                return $oAdminObjectController;
            }));

        $oObjectHandler = new UserAccessManager(
            $oPhp,
            $oWordpress,
            $oConfig,
            $oObjectHandler,
            $oAccessHandler,
            $oSetupHandler,
            $oControllerFactory
        );

        $oObjectHandler->registerAdminActionsAndFilters();
        $oObjectHandler->registerAdminActionsAndFilters();
        $oObjectHandler->registerAdminActionsAndFilters();
    }

    /**
     * @group  unit
     * @covers \UserAccessManager\UserAccessManager::addActionsAndFilters()
     */
    public function testAddActionsAndFilters()
    {
        $oFrontendController = $this->createMock('UserAccessManager\Controller\FrontendController');
        $oFrontendController->expects($this->exactly(3))
            ->method('getRequestParameter')
            ->will($this->onConsecutiveCalls(null, true, true));

        $oControllerFactory = $this->getControllerFactory();
        $oControllerFactory->expects($this->exactly(3))
            ->method('createFrontendController')
            ->will($this->returnValue($oFrontendController));

        $oWordpress = $this->getWordpress();
        $oWordpress->expects($this->exactly(21))
            ->method('addAction');

        $oWordpress->expects($this->exactly(62))
            ->method('addFilter');

        $oConfig = $this->getConfig();
        $oConfig->expects($this->exactly(3))
            ->method('getRedirect')
            ->will($this->onConsecutiveCalls(false, false, true));

        $oObjectHandler = new UserAccessManager(
            $this->getPhp(),
            $oWordpress,
            $oConfig,
            $this->getObjectHandler(),
            $this->getAccessHandler(),
            $this->getSetupHandler(),
            $oControllerFactory
        );

        $oObjectHandler->addActionsAndFilters();
        $oObjectHandler->addActionsAndFilters();
        $oObjectHandler->addActionsAndFilters();
    }
}
