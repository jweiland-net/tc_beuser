<?php
namespace dkd\TcBeuser\Module;

/**
 * Created by PhpStorm.
 * User: dkd-kartolo
 * Date: 20/04/16
 * Time: 14:18
 */

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\BaseScriptClass;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Class AbstractModuleController
 * This class shares methods, which are used by other module classes.
 *
 * @author Ivan Kartolo <ivan.kartolo@dkd.de>
 * @package dkd\TcBeuser\Controller
 */
abstract class AbstractModuleController extends BaseScriptClass
{

    /**
     * Name of the module
     *
     * @var string
     */
    protected $moduleName = '';

    /**
     * ModuleTemplate object
     *
     * @var ModuleTemplate
     */
    protected $moduleTemplate;

    /**
     * Return URL script, processed. This contains the script (if any) that we should
     * RETURN TO from the FormEngine script IF we press the close button. Thus this
     * variable is normally passed along from the calling script so we can properly return if needed.
     *
     * @var string
     */
    public $retUrl;

    /**
     * Contains the parts of the REQUEST_URI (current url). By parts we mean the result of resolving
     * REQUEST_URI (current url) by the parse_url() function. The result is an array where eg. "path"
     * is the script path and "query" is the parameters...
     *
     * @var array
     */
    public $R_URL_parts;

    /**
     * Contains the current GET vars array; More specifically this array is the foundation for creating
     * the R_URI internal var (which becomes the "url of this script" to which we submit the forms etc.)
     *
     * @var array
     */
    public $R_URL_getvars;

    /**
     * Set to the URL of this script including variables which is needed to re-display the form. See main()
     *
     * @var string
     */
    public $R_URI;

    /**
     * Commalist of fieldnames to edit. The point is IF you specify this list, only those
     * fields will be rendered in the form. Otherwise all (available) fields in the record
     * is shown according to the types configuration in $GLOBALS['TCA']
     *
     * @var bool
     */
    public $columnsOnly;

    /**
     * Default values for fields (array with tablenames, fields etc. as keys).
     * Can be seen modified internally.
     *
     * @var array
     */
    public $defVals;

    /**
     * Array of values to force being set (as hidden fields). Will be set as $this->defVals
     * IF defVals does not exist.
     *
     * @var array
     */
    public $overrideVals;

    /**
     * If set, this value will be set in $this->retUrl (which is used quite many places
     * as the return URL). If not set, "dummy.php" will be set in $this->retUrl
     *
     * @var string
     */
    public $returnUrl;

    /**
     * Close-document command. Not really sure of all options...
     *
     * @var int
     */
    public $closeDoc;

    /**
     * IconFactory
     *
     * @var IconFactory
     */
    protected $iconFactory;

    /**
     * Alternative URL for viewing the frontend pages.
     *
     * @var string
     */
    public $viewUrl;

    /**
     * Boolean: If set, then the GET var "&id=" will be added to the
     * retUrl string so that the NEW id of something is returned to the script calling the form.
     *
     * @var bool
     */
    public $returnNewPageId;

    /**
     * Is set to the pid value of the last shown record - thus indicating which page to
     * show when clicking the SAVE/VIEW button
     *
     * @var int
     */
    public $viewId;

    /**
     * Is set to additional parameters (like "&L=xxx") if the record supports it.
     *
     * @var string
     */
    public $viewId_addParams;

    /**
     * Pointer to the first element in $elementsData
     *
     * @var array
     */
    public $firstEl;

    /**
     * Counter, used to count the number of errors (when users do not have edit permissions)
     *
     * @var int
     */
    public $errorC;

    /**
     * Quite simply, if this variable is set, then the processing of incoming data will be performed
     * as if a save-button is pressed. Used in the forms as a hidden field which can be set through
     * JavaScript if the form is somehow submitted by JavaScript).
     *
     * @var bool
     */
    public $doSave;

    /**
     * GPvar "edit": Is an array looking approx like [tablename][list-of-ids]=command, eg.
     * "&edit[pages][123]=edit". See \TYPO3\CMS\Backend\Utility\BackendUtility::editOnClick(). Value can be seen
     * modified internally (converting NEW keyword to id, workspace/versioning etc).
     *
     * @var array
     */
    public $editconf;

    /**
     * working only with be_users table
     *
     * @var string
     */
    public $table = 'be_users';


    /**
     * Constructor
     */
    public function __construct()
    {
        $GLOBALS['MCONF'] = $this->MCONF = array(
            'name' => $this->moduleName
        );

        $this->moduleTemplate = GeneralUtility::makeInstance(ModuleTemplate::class);

        $this->moduleTemplate->getPageRenderer()->loadJquery();
        $this->moduleTemplate->getPageRenderer()->loadRequireJsModule('TYPO3/CMS/Recordlist/FieldSelectBox');
        $this->moduleTemplate->getPageRenderer()->loadRequireJsModule('TYPO3/CMS/Recordlist/Recordlist');
        $this->moduleTemplate->getPageRenderer()->loadRequireJsModule('TYPO3/CMS/Backend/AjaxDataHandler');
        $this->moduleTemplate->addJavaScriptCode(
            'jumpToUrl',
            '
                function jumpToUrl(URL) {
                    window.location.href = URL;
                    return false;
                }
                '
        );
        $this->getInlineJS();
    }

    /**
     * Entrance from the backend module. This replace the _dispatch
     *
     * @param ServerRequestInterface $request The request object from the backend
     * @param ResponseInterface $response The reponse object sent to the backend
     *
     * @return ResponseInterface Return the response object
     */
    public function mainAction(ServerRequestInterface $request, ResponseInterface $response)
    {
        $this->loadLocallang();

        $this->preInit();
        if ($this->doProcessData()) {
            // Checks, if a save button has been clicked (or the doSave variable is sent)
            $this->processData();
        }

        $this->main();
        $this->moduleTemplate->setContent($this->content);
        $response->getBody()->write($this->moduleTemplate->renderContent());
        return $response;
    }

    /**
     * Dummy main function
     */
    abstract public function main();

    abstract public function loadLocallang();

    abstract public function processData();

    /**
     * First initialization.
     *
     * @return void
     */
    public function preInit()
    {
        // Setting GPvars:
        $this->editconf = GeneralUtility::_GP('edit');
        $this->defVals = GeneralUtility::_GP('defVals');
        $this->overrideVals = GeneralUtility::_GP('overrideVals');
        $this->columnsOnly = GeneralUtility::_GP('columnsOnly');
        $this->returnUrl = GeneralUtility::_GP('returnUrl');
        $this->closeDoc = GeneralUtility::_GP('closeDoc');
        $this->doSave = GeneralUtility::_GP('doSave');
        $this->returnEditConf = GeneralUtility::_GP('returnEditConf');

        // Setting override values as default if defVals does not exist.
        if (!is_array($this->defVals) && is_array($this->overrideVals)) {
            $this->defVals = $this->overrideVals;
        }

        //get pid FE
        $this->extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['tc_beuser']);

        // Setting return URL
        $this->retUrl = $this->returnUrl ? $this->returnUrl
            : BackendUtility::getModuleUrl($GLOBALS['MCONF']['name'], array('SET[function]' => 1));

        // Make R_URL (request url) based on input GETvars:
        $this->R_URL_parts = parse_url(GeneralUtility::getIndpEnv('REQUEST_URI'));
        $this->R_URL_getvars = GeneralUtility::_GET();
        $this->R_URL_getvars['edit'] = $this->editconf ?: array($this->table => array('new'));

        // Set other internal variables:
        $this->R_URL_getvars['returnUrl'] = $this->retUrl;
        $this->R_URI = $this->R_URL_parts['path'] . '?' . ltrim(GeneralUtility::implodeArrayForUrl(
            '',
            $this->R_URL_getvars
        ), '&');

        if ($this->closeDoc > 0) {
            $this->closeDocument();
        }

        $this->iconFactory = GeneralUtility::makeInstance(IconFactory::class);
    }

    /**
     * Detects, if a save command has been triggered.
     *
     * @return boolean True, then save the document (data submitted)
     */
    public function doProcessData()
    {
        $out = $this->doSave ||
            isset($_POST['_savedok']) ||
            isset($_POST['_saveandclosedok']);
        return $out;
    }

    /**
     * Create the panel of buttons for submitting the form or otherwise perform operations.
     */
    protected function getButtons()
    {
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        // Shortcut
        $shortCutButton = $buttonBar->makeShortcutButton()
            ->setModuleName($this->moduleName)
            ->setDisplayName($this->MOD_MENU['function'][$this->MOD_SETTINGS['function']])
            ->setGetVariables([
                'M',
                'id',
                'edit_record',
                'pointer',
                'new_unique_uid',
                'search_field',
                'search_levels',
                'showLimit'
            ])
            ->setSetVariables(array_keys($this->MOD_MENU));
        $buttonBar->addButton($shortCutButton, ButtonBar::BUTTON_POSITION_RIGHT);
    }

    /**
     * Generate the ModuleMenu
     *
     * @param string @identifier identifier of the generated menu
     */
    protected function generateMenu($identifier)
    {
        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier($identifier);
        foreach ($this->MOD_MENU['function'] as $controller => $title) {
            $item = $menu
                ->makeMenuItem()
                ->setHref(
                    BackendUtility::getModuleUrl(
                        $this->moduleName,
                        [
                            'id' => $this->id,
                            'SET' => [
                                'function' => $controller
                            ]
                        ]
                    )
                )
                ->setTitle($title);
            if ($controller == $this->MOD_SETTINGS['function']) {
                $item->setActive(true);
            }
            $menu->addMenuItem($item);
        }
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($menu);
    }

    /**
     * Create the panel of buttons for submitting the form or otherwise perform operations.
     *
     * @return array All available buttons as an assoc. array
     */
    protected function getSaveButton()
    {
        $lang = $this->getLanguageService();
        // Render SAVE type buttons:
        // The action of each button is decided by its name attribute. (See doProcessData())
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        if (!$this->errorC && !$GLOBALS['TCA'][$this->firstEl['table']]['ctrl']['readOnly']) {
            $saveSplitButton = $buttonBar->makeSplitButton();
            // SAVE button:
            $saveButton = $buttonBar->makeInputButton()
                ->setTitle($lang->sL('LLL:EXT:lang/locallang_core.xlf:rm.saveDoc'))
                ->setName('_savedok')
                ->setValue('1')
                ->setForm('EditDocumentController')
                ->setIcon($this->moduleTemplate->getIconFactory()->getIcon('actions-document-save', Icon::SIZE_SMALL));
            $saveSplitButton->addItem($saveButton, true);

            // SAVE / CLOSE
            $saveAndCloseButton = $buttonBar->makeInputButton()
                ->setName('_saveandclosedok')
                ->setClasses('t3js-editform-submitButton')
                ->setValue('1')
                ->setForm('EditDocumentController')
                ->setTitle($lang->sL('LLL:EXT:lang/locallang_core.xlf:rm.saveCloseDoc'))
                ->setIcon($this->moduleTemplate->getIconFactory()->getIcon(
                    'actions-document-save-close',
                    Icon::SIZE_SMALL
                ));
            $saveSplitButton->addItem($saveAndCloseButton);
            $buttonBar->addButton($saveSplitButton, ButtonBar::BUTTON_POSITION_LEFT, 2);
        }
        // CLOSE button:
        $closeButton = $buttonBar->makeLinkButton()
            ->setHref('#')
            ->setClasses('t3js-editform-close')
            ->setTitle($lang->sL('LLL:EXT:lang/locallang_core.xlf:rm.closeDoc'))
            ->setIcon($this->moduleTemplate->getIconFactory()->getIcon(
                'actions-document-close',
                Icon::SIZE_SMALL
            ));
        $buttonBar->addButton($closeButton);

        // DELETE button:
        if (!$this->errorC
            && !$GLOBALS['TCA'][$this->firstEl['table']]['ctrl']['readOnly']
        ) {
            if ($this->firstEl['cmd'] !== 'new' && MathUtility::canBeInterpretedAsInteger($this->firstEl['uid'])) {
                // Delete:
                if ($this->firstEl['deleteAccess']
                    && !$GLOBALS['TCA'][$this->firstEl['table']]['ctrl']['readOnly']
                    && !$this->getNewIconMode($this->firstEl['table'], 'disableDelete')
                ) {
                    $returnUrl = $this->retUrl;
                    if ($this->firstEl['table'] === 'pages') {
                        parse_str((string)parse_url($returnUrl, PHP_URL_QUERY), $queryParams);
                        if (isset($queryParams['M'])
                            && isset($queryParams['id'])
                            && (string)$this->firstEl['uid'] === (string)$queryParams['id']
                        ) {
                            // TODO: Use the page's pid instead of 0, this requires a clean API to manipulate the page
                            // tree from the outside to be able to mark the pid as active
                            $returnUrl = BackendUtility::getModuleUrl($queryParams['M'], ['id' => 0]);
                        }
                    }
                    $deleteButton = $buttonBar->makeLinkButton()
                        ->setHref('#')
                        ->setClasses('t3js-editform-delete-record')
                        ->setTitle($lang->sL('LLL:EXT:backend/Resources/Private/Language/locallang_layout.xlf:deleteItem'))
                        ->setIcon($this->moduleTemplate->getIconFactory()->getIcon(
                            'actions-edit-delete',
                            Icon::SIZE_SMALL
                        ))
                        ->setDataAttributes([
                            'return-url' => $returnUrl,
                            'uid' => $this->firstEl['uid'],
                            'table' => $this->firstEl['table']
                        ]);
                    $buttonBar->addButton($deleteButton, ButtonBar::BUTTON_POSITION_LEFT, 3);
                }
            }
        }

        $cshButton = $buttonBar->makeHelpButton()->setModuleName('xMOD_csh_corebe')->setFieldName('TCEforms');
        $buttonBar->addButton($cshButton);
    }

    /**
     * Put together the various elements (buttons, selectors, form) into a table
     *
     * @param string $editForm HTML form.
     * @return string Composite HTML
     */
    public function compileForm($editForm)
    {
        $formContent = '
			<!-- EDITING FORM -->
			<form
            action="' . htmlspecialchars($this->R_URI) . '"
            method="post"
            enctype="multipart/form-data"
            name="editform"
            id="EditDocumentController"
            onsubmit="TBE_EDITOR.checkAndDoSubmit(1); return false;">
			' . $editForm . '

			<input type="hidden" name="returnUrl" value="' . htmlspecialchars($this->retUrl) . '" />
			<input type="hidden" name="viewUrl" value="' . htmlspecialchars($this->viewUrl) . '" />';
        if ($this->returnNewPageId) {
            $formContent .= '<input type="hidden" name="returnNewPageId" value="1" />';
        }
        $formContent .= '<input type="hidden" name="popViewId" value="' . htmlspecialchars($this->viewId) . '" />';
        if ($this->viewId_addParams) {
            $formContent .= '<input type="hidden" name="popViewId_addParams" value="' .
                htmlspecialchars($this->viewId_addParams) . '" />';
        }
        $formContent .= '
			<input type="hidden" name="closeDoc" value="0" />
			<input type="hidden" name="doSave" value="0" />
			<input type="hidden" name="_serialNumber" value="' . md5(microtime()) . '" />
			<input type="hidden" name="_scrollPosition" value="" />';
        return $formContent;
    }

    /**
     * close the document and send to the previous page
     */
    public function closeDocument()
    {
        HttpUtility::redirect($this->retUrl);
    }

    /**
     * Function used to look for configuration of buttons in the form: Fx. disabling buttons or showing them at various
     * positions.
     *
     * @param string $table The table for which the configuration may be specific
     * @param string $key The option for look for. Default is checking if the saveDocNew button should be displayed.
     * @return string Return value fetched from USER TSconfig
     */
    public function getNewIconMode($table, $key = 'saveDocNew')
    {
        $TSconfig = $this->getBackendUser()->getTSConfig('options.' . $key);
        $output = trim(isset($TSconfig['properties'][$table]) ? $TSconfig['properties'][$table] : $TSconfig['value']);
        return $output;
    }


    public function getInlineJS()
    {
        // Add JavaScript functions to the page:

        $this->moduleTemplate->addJavaScriptCode(
            'TcToolsInlineJS',
            '
				function jumpExt(URL,anchor) {	//
					var anc = anchor?anchor:"";
					window.location.href = URL+(T3_THIS_LOCATION?"&returnUrl="+T3_THIS_LOCATION:"")+anc;
					return false;
				}
				function jumpSelf(URL) {	//
					window.location.href = URL+(T3_RETURN_URL?"&returnUrl="+T3_RETURN_URL:"");
					return false;
				}
				function jumpToUrl(URL) {
					window.location.href = URL;
					return false;
				}

				function setHighlight(id) {	//
					top.fsMod.recentIds["tcTools"]=id;
					top.fsMod.navFrameHighlightedID["web"]="pages"+id+"_"+top.fsMod.currentBank;	// For highlighting

					if (top.content && top.content.nav_frame && top.content.nav_frame.refresh_nav) {
						top.content.nav_frame.refresh_nav();
					}
				}
				
				function editRecords(table,idList,addParams,CBflag) {	//
					window.location.href="' . BackendUtility::getModuleUrl('record_edit', array('returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI'))) . '&edit["+table+"]["+idList+"]=edit"+addParams;
				}
				function editList(table,idList) {	//
					var list="";

						// Checking how many is checked, how many is not
					var pointer=0;
					var pos = idList.indexOf(",");
					while (pos!=-1) {
						if (cbValue(table+"|"+idList.substr(pointer,pos-pointer))) {
							list+=idList.substr(pointer,pos-pointer)+",";
						}
						pointer=pos+1;
						pos = idList.indexOf(",",pointer);
					}
					if (cbValue(table+"|"+idList.substr(pointer))) {
						list+=idList.substr(pointer)+",";
					}

					return list ? list : idList;
				}
				function deleteRecord(table,id,url) {	//
                    window.location.href = ' . GeneralUtility::quoteJSvalue(BackendUtility::getModuleUrl($this->moduleName) . '&cmd[') . '+table+"]["+id+"][delete]=1&redirect="+escape(url)+"&vC=' . $this->getBackendUser()->veriCode() . '&prErr=1&uPT=1&SET[function]=action";
                }

				if (top.fsMod) top.fsMod.recentIds["tcTools"] = ' . (int)$this->id . ';
			'
        );
    }
}
