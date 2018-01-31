<?php
/**
 * Created by PhpStorm.
 * User: dkd-kartolo
 * Date: 27/04/16
 * Time: 15:06
 */

namespace dkd\TcBeuser\Xclass;

use dkd\TcBeuser\Utility\TcBeuserUtility;
use TYPO3\CMS\Backend\Controller\ContentElement\ElementInformationController;

class RecordInfoController extends ElementInformationController
{

    /**
     * Overwrite the init method of the parent object
     * We set the current user as fake admin, so that
     * he get the permission to show the info.
     *
     * @return void
     */
    public function init()
    {
        // fake admin
        if ($this->getBackendUser()->user['admin'] != 1) {
            //make fake Admin
            TcBeuserUtility::fakeAdmin();
        }
        parent::init();
    }

}
