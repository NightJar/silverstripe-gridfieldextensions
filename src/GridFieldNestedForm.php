<?php

namespace Symbiote\GridFieldExtensions;

use Exception;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\RequestHandler;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_DataManipulator;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\GridField\GridField_SaveHandler;
use SilverStripe\Forms\GridField\GridField_URLHandler;
use SilverStripe\Forms\GridField\GridFieldStateAware;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\ORM\Filterable;
use SilverStripe\ORM\Hierarchy\Hierarchy;
use SilverStripe\ORM\SS_List;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\ViewableData;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;

/**
 * Gridfield component for nesting GridFields
 */
class GridFieldNestedForm extends AbstractGridFieldComponent implements
    GridField_URLHandler,
    GridField_ColumnProvider,
    GridField_SaveHandler,
    GridField_HTMLProvider,
    GridField_DataManipulator
{
    use Configurable, GridFieldStateAware;
    
    /**
     * The key used in the post data to identify nested form data
     */
    const POST_KEY = 'GridFieldNestedForm';

    private static $allowed_actions = [
        'handleNestedItem'
    ];

    /**
     * The default max nesting level. Nesting further than this will throw an exception.
     *
     * @var boolean
     */
    private static $default_max_nesting_level = 10;
    
    /**
     * @var string
     */
    private $name;

    /**
     * @var bool
     */
    private $expandNested = false;

    /**
     * @var bool
     */
    private $forceCloseNested = false;

    /**
     * @var GridField
     */
    private $gridField = null;

    /**
     * @var string
     */
    private $relationName = 'Children';

    /**
     * @var bool
     */
    private $inlineEditable = false;

    /**
     * @var callable|string
     */
    private $canExpandCheck = null;

    /**
     * @var int
     */
    private $maxNestingLevel = null;
    
    public function __construct($name = 'NestedForm')
    {
        $this->name = $name;
    }
    
    /**
     * Get the grid field that this component is attached to
     * @return GridField
     */
    public function getGridField()
    {
        return $this->gridField;
    }

    /**
     * Get the relation name to use for the nested grid fields
     * @return string
     */
    public function getRelationName()
    {
        return $this->relationName;
    }
    
    /**
     * Set the relation name to use for the nested grid fields
     * @param string $relationName
     */
    public function setRelationName($relationName)
    {
        $this->relationName = $relationName;
        return $this;
    }

    /**
     * Get whether the nested grid fields should be inline editable
     * @return boolean
     */
    public function getInlineEditable()
    {
        return $this->inlineEditable;
    }

    /**
     * Set whether the nested grid fields should be inline editable
     * @param boolean $editable
     */
    public function setInlineEditable($editable)
    {
        $this->inlineEditable = $editable;
        return $this;
    }
    
    /**
     * Set whether the nested grid fields should be expanded by default
     * @param boolean $expandNested
     */
    public function setExpandNested($expandNested)
    {
        $this->expandNested = $expandNested;
        return $this;
    }

    /**
     * Set whether the nested grid fields should be forced closed on load
     * @param boolean $forceClosed
     */
    public function setForceClosedNested($forceClosed)
    {
        $this->forceCloseNested = $forceClosed;
        return $this;
    }

    /**
     * Set a callback function to check which items in this grid that should show the expand link
     * for nested gridfields. The callback should return a boolean value.
     * You can either pass a callable or a method name as a string.
     * @param callable|string $callback
     */
    public function setCanExpandCheck($callback)
    {
        $this->canExpandCheck = $callback;
        return $this;
    }

    /**
     * Set the maximum nesting level allowed for nested grid fields
     * @param int $level
     */
    public function setMaxNestingLevel($level)
    {
        $this->maxNestingLevel = $level;
        return $this;
    }

    /**
     * Get the max nesting level allowed for this grid field.
     * @return int
     */
    public function getMaxNestingLevel()
    {
        return $this->maxNestingLevel ?: static::config()->get('default_max_nesting_level');
    }

    /**
     * Check if we are currently at the max nesting level allowed.
     * @return bool
     */
    protected function atMaxNestingLevel(GridField $gridField): bool
    {
        $level = 0;
        $controller = $gridField->getForm()->getController();
        $maxLevel = $this->getMaxNestingLevel();
        while ($level < $maxLevel && $controller && $controller instanceof GridFieldNestedFormItemRequest) {
            $controller = $controller->getController();
            $level++;
        }
        return $level >= $maxLevel;
    }

    public function getColumnMetadata($gridField, $columnName)
    {
        return ['title' => ''];
    }

    public function getColumnsHandled($gridField)
    {
        return ['ToggleNested'];
    }

    public function getColumnAttributes($gridField, $record, $columnName)
    {
        return ['class' => 'col-listChildrenLink grid-field__col-compact'];
    }

    public function augmentColumns($gridField, &$columns)
    {
        if (!in_array('ToggleNested', $columns)) {
            array_splice($columns, 0, 0, 'ToggleNested');
        }
    }

    public function getColumnContent($gridField, $record, $columnName)
    {
        if ($this->atMaxNestingLevel($gridField)) {
            return '';
        }
        $gridField->addExtraClass('has-nested');
        if ($record->ID && $record->exists()) {
            $this->gridField = $gridField;
            $relationName = $this->getRelationName();
            if (!$record->hasMethod($relationName)) {
                return '';
            }
            if ($this->canExpandCheck) {
                if (is_callable($this->canExpandCheck)
                    && !call_user_func($this->canExpandCheck, $record)
                ) {
                    return '';
                } elseif (is_string($this->canExpandCheck)
                    && $record->hasMethod($this->canExpandCheck)
                    && !$record->{$this->canExpandCheck}($record)
                ) {
                    return '';
                }
            }
            $toggle = 'closed';
            $className = str_replace('\\', '-', get_class($record));
            $state = $gridField->State->GridFieldNestedForm;
            $stateRelation = $className.'-'.$record->ID.'-'.$this->relationName;
            $openState = $state && (int)$state->getData($stateRelation) === 1;
            $forceExpand = $this->expandNested && $record->$relationName()->count() > 0;
            if (!$this->forceCloseNested
                && ($forceExpand || $openState)
            ) {
                $toggle = 'open';
            }

            GridFieldExtensions::include_requirements();

            return ViewableData::create()->customise([
                'Toggle' => $toggle,
                'Link' => $this->Link($record->ID),
                'ToggleLink' => $this->ToggleLink($record->ID),
                'PjaxFragment' => $stateRelation,
                'NestedField' => ($toggle == 'open') ? $this->handleNestedItem($gridField, null, $record): ' '
            ])->renderWith('Symbiote\GridFieldExtensions\GridFieldNestedForm');
        }
    }
    
    public function getURLHandlers($gridField)
    {
        return [
            'nested/$RecordID/$NestedAction' => 'handleNestedItem',
            'toggle/$RecordID' => 'toggleNestedItem',
            'POST movetoparent' => 'handleMoveToParent'
        ];
    }

    /**
     * @param GridField $gridField
     * @return array
     */
    public function getHTMLFragments($gridField)
    {
        /**
         * If we have a DataObject with the hierarchy extension, we want to allow moving items to a new parent.
         * This is enabled by setting the data-url-movetoparent attribute on the grid field, so that the client
         * javascript can handle the move.
         * Implemented in getHTMLFragments since this attribute needs to be added before any rendering happens.
         */
        if (is_a($gridField->getModelClass(), DataObject::class, true)
            && DataObject::has_extension($gridField->getModelClass(), Hierarchy::class)
        ) {
            $gridField->setAttribute('data-url-movetoparent', $gridField->Link('movetoparent'));
        }
        return [];
    }

    /**
     * Handle moving a record to a new parent
     *
     * @return string
     */
    public function handleMoveToParent(GridField $gridField, $request)
    {
        $move = $request->postVar('move');
        /** @var DataList */
        $list = $gridField->getList();
        $id = isset($move['id']) ? (int) $move['id'] : null;
        if (!$id) {
            throw new HTTPResponse_Exception('Missing ID', 400);
        }
        $to = isset($move['parent']) ? (int)$move['parent'] : null;
        // should be possible either on parent or child grid field, or nested grid field from parent
        $parent = $to ? $list->byID($to) : null;
        if (!$parent
            && $to
            && $gridField->getForm()->getController() instanceof GridFieldNestedFormItemRequest
            && $gridField->getForm()->getController()->getRecord()->ID == $to
        ) {
            $parent = $gridField->getForm()->getController()->getRecord();
        }
        $child = $list->byID($id);
        // we need either a parent or a child, or a move to top level at this stage
        if (!($parent || $child || $to === 0)) {
            throw new HTTPResponse_Exception('Invalid request', 400);
        }
        // parent or child might be from another grid field, so we need to search via DataList in some cases
        if (!$parent && $to) {
            $parent = DataList::create($gridField->getModelClass())->byID($to);
        }
        if (!$child) {
            $child = DataList::create($gridField->getModelClass())->byID($id);
        }
        if ($child) {
            if (!$child->canEdit()) {
                throw new HTTPResponse_Exception('Not allowed', 403);
            }
            if ($child->hasExtension(Hierarchy::class)) {
                $child->ParentID = $parent ? $parent->ID : 0;
            }
            // validate that the record is still valid
            $validationResult = $child->validate();
            if ($validationResult->isValid()) {
                if ($child->hasExtension(Versioned::class)) {
                    $child->writeToStage(Versioned::DRAFT);
                } else {
                    $child->write();
                }

                // reorder items at the same time, if applicable
                /** @var GridFieldOrderableRows */
                $orderableRows = $gridField->getConfig()->getComponentByType(GridFieldOrderableRows::class);
                if ($orderableRows) {
                    $orderableRows->setImmediateUpdate(true);
                    try {
                        $orderableRows->handleReorder($gridField, $request);
                    } catch (Exception $e) {
                    }
                }
            } else {
                $messages = $validationResult->getMessages();
                $message = array_pop($messages);
                throw new HTTPResponse_Exception($message['message'], 400);
            }
        }
        return $gridField->FieldHolder();
    }
    
    /**
     * Handle the request to show a nested item
     *
     * @param GridField $gridField
     * @param HTTPRequest|null $request
     * @param ViewableData|null $record
     * @return HTTPResponse|RequestHandler
     */
    public function handleNestedItem(GridField $gridField, $request = null, $record = null)
    {
        if ($this->atMaxNestingLevel($gridField)) {
            throw new Exception('Max nesting level reached');
        }
        $list = $gridField->getList();
        if (!$record && $request && $list instanceof Filterable) {
            $recordID = $request->param('RecordID');
            $record = $list->byID($recordID);
        }
        if (!$record) {
            return '';
        }
        $relationName = $this->getRelationName();
        if (!$record->hasMethod($relationName)) {
            throw new Exception('Invalid relation name');
        }
        $manager = $this->getStateManager();
        $stateRequest = $request ?: $gridField->getForm()->getRequestHandler()->getRequest();
        if ($gridStateStr = $manager->getStateFromRequest($gridField, $stateRequest)) {
            $gridField->getState(false)->setValue($gridStateStr);
        }
        $this->gridField = $gridField;
        $itemRequest = GridFieldNestedFormItemRequest::create(
            $gridField,
            $this,
            $record,
            $gridField->getForm()->getController(),
            $this->name
        );
        if ($request) {
            $pjaxFragment = $request->getHeader('X-Pjax');
            $targetPjaxFragment = str_replace('\\', '-', get_class($record)).'-'.$record->ID.'-'.$this->relationName;
            if ($pjaxFragment == $targetPjaxFragment) {
                $pjaxReturn = [$pjaxFragment => $itemRequest->ItemEditForm()->Fields()->first()->forTemplate()];
                $response = new HTTPResponse(json_encode($pjaxReturn));
                $response->addHeader('Content-Type', 'text/json');
                return $response;
            } else {
                return $itemRequest->ItemEditForm();
            }
        } else {
            return $itemRequest->ItemEditForm()->Fields()->first();
        }
    }

    /**
     * Handle the request to toggle a nested item in the gridfield state
     *
     * @param GridField $gridField
     * @param HTTPRequest|null $request
     * @param ViewableData|null $record
     */
    public function toggleNestedItem(GridField $gridField, $request = null, $record = null)
    {
        $list = $gridField->getList();
        if (!$record && $request && $list instanceof Filterable) {
            $recordID = $request->param('RecordID');
            $record = $list->byID($recordID);
        }
        $manager = $this->getStateManager();
        if ($gridStateStr = $manager->getStateFromRequest($gridField, $request)) {
            $gridField->getState(false)->setValue($gridStateStr);
        }
        $className = str_replace('\\', '-', get_class($record));
        $state = $gridField->getState()->GridFieldNestedForm;
        $stateRelation = $className.'-'.$record->ID.'-'.$this->getRelationName();
        $state->$stateRelation = (int)$request->getVar('toggle');
    }
    
    /**
     * Get the link for the nested grid field
     *
     * @param string $action
     * @return string
     */
    public function Link($action = null)
    {
        $link = Director::absoluteURL(Controller::join_links($this->gridField->Link('nested'), $action));
        $manager = $this->getStateManager();
        return $manager->addStateToURL($this->gridField, $link);
    }

    /**
     * Get the link for the toggle action
     *
     * @param string $action
     * @return string
     */
    public function ToggleLink($action = null)
    {
        $link = Director::absoluteURL(Controller::join_links($this->gridField->Link('toggle'), $action, '?toggle='));
        $manager = $this->getStateManager();
        return $manager->addStateToURL($this->gridField, $link);
    }
    
    public function handleSave(GridField $gridField, DataObjectInterface $record)
    {
        $postKey = self::POST_KEY;
        $value = $gridField->Value();
        if (isset($value['GridState']) && $value['GridState']) {
            // set grid state from value, to store open/closed toggle state for nested forms
            $gridField->getState(false)->setValue($value['GridState']);
        }
        $manager = $this->getStateManager();
        $request = $gridField->getForm()->getRequestHandler()->getRequest();
        if ($gridStateStr = $manager->getStateFromRequest($gridField, $request)) {
            $gridField->getState(false)->setValue($gridStateStr);
        }
        foreach ($request->postVars() as $key => $val) {
            $list = $gridField->getList();
            if ($list instanceof Filterable && preg_match("/{$gridField->getName()}-{$postKey}-(\d+)/", $key, $matches)) {
                $recordID = $matches[1];
                $nestedData = $val;
                $record = $list->byID($recordID);
                if ($record) {
                    /** @var GridField */
                    $nestedGridField = $this->handleNestedItem($gridField, null, $record);
                    $nestedGridField->setValue($nestedData);
                    $nestedGridField->saveInto($record);
                }
            }
        }
    }

    public function getManipulatedData(GridField $gridField, SS_List $dataList)
    {
        if ($this->relationName == 'Children'
            && is_a($gridField->getModelClass(), DataObject::class, true)
            && DataObject::has_extension($gridField->getModelClass(), Hierarchy::class)
            && $gridField->getForm()->getController() instanceof ModelAdmin
            && $dataList instanceof Filterable
        ) {
            $dataList = $dataList->filter('ParentID', 0);
        }
        return $dataList;
    }
}
