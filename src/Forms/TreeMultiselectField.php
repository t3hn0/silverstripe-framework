<?php

namespace SilverStripe\Forms;

use SilverStripe\Core\Convert;
use SilverStripe\Control\Controller;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\View\Requirements;
use SilverStripe\View\ViewableData;
use stdClass;

/**
 * This formfield represents many-many joins using a tree selector shown in a dropdown styled element
 * which can be added to any form usually in the CMS.
 *
 * This form class allows you to represent Many-Many Joins in a handy single field. The field has javascript which
 * generates a AJAX tree of the site structure allowing you to save selected options to a component set on a given
 * {@link DataObject}.
 *
 * <b>Saving</b>
 *
 * This field saves a {@link ComponentSet} object which is present on the {@link DataObject} passed by the form,
 * returned by calling a function with the same name as the field. The Join is updated by running setByIDList on the
 * {@link ComponentSet}
 *
 * <b>Customizing Save Behaviour</b>
 *
 * Before the data is saved, you can modify the ID list sent to the {@link ComponentSet} by specifying a function on
 * the {@link DataObject} called "onChange[fieldname](&items)". This will be passed by reference the IDlist (an array
 * of ID's) from the Treefield to be saved to the component set.
 *
 * Returning false on this method will prevent treemultiselect from saving to the {@link ComponentSet} of the given
 * {@link DataObject}
 *
 * <code>
 * // Called when we try and set the Parents() component set
 * // by Tree Multiselect Field in the administration.
 * function onChangeParents(&$items) {
 *  // This ensures this DataObject can never be a parent of itself
 *  if($items){
 *      foreach($items as $k => $id){
 *          if($id == $this->ID){
 *              unset($items[$k]);
 *          }
 *      }
 *  }
 *  return true;
 * }
 * </code>
 *
 * @see TreeDropdownField for the sample implementation, but only allowing single selects
 */
class TreeMultiselectField extends TreeDropdownField
{
    public function __construct($name, $title = null, $sourceObject = "SilverStripe\\Security\\Group", $keyField = "ID", $labelField = "Title")
    {
        parent::__construct($name, $title, $sourceObject, $keyField, $labelField);
        $this->removeExtraClass('single');
        $this->addExtraClass('multiple');
        $this->value = 'unchanged';
    }

    /**
     * Return this field's linked items
     */
    public function getItems()
    {
        // If the value has been set, use that
        if ($this->value != 'unchanged' && is_array($this->sourceObject)) {
            $items = array();
            $values = is_array($this->value) ? $this->value : preg_split('/ *, */', trim($this->value));
            foreach ($values as $value) {
                $item = new stdClass;
                $item->ID = $value;
                $item->Title = $this->sourceObject[$value];
                $items[] = $item;
            }
            return $items;

        // Otherwise, look data up from the linked relation
        } if ($this->value != 'unchanged' && is_string($this->value)) {
            $items = new ArrayList();
            $ids = explode(',', $this->value);
            foreach ($ids as $id) {
                if (!is_numeric($id)) {
                    continue;
                }
                $item = DataObject::get_by_id($this->sourceObject, $id);
                if ($item) {
                    $items->push($item);
                }
            }
            return $items;
        } elseif ($this->form) {
            $fieldName = $this->name;
            $record = $this->form->getRecord();
            if (is_object($record) && $record->hasMethod($fieldName)) {
                return $record->$fieldName();
            }
        }
    }

    /**
     * We overwrite the field attribute to add our hidden fields, as this
     * formfield can contain multiple values.
     *
     * @param array $properties
     * @return DBHTMLText
     */
    public function Field($properties = array())
    {
        $value = '';
        $titleArray = array();
        $idArray = array();
        $items = $this->getItems();
        $emptyTitle = _t('SilverStripe\\Forms\\DropdownField.CHOOSE', '(Choose)', 'start value of a dropdown');

        if ($items && count($items)) {
            foreach ($items as $item) {
                $idArray[] = $item->ID;
                $titleArray[] = ($item instanceof ViewableData)
                    ? $item->obj($this->labelField)->forTemplate()
                    : Convert::raw2xml($item->{$this->labelField});
            }

            $title = implode(", ", $titleArray);
            $value = implode(",", $idArray);
        } else {
            $title = $emptyTitle;
        }

        $dataUrlTree = '';
        if ($this->form) {
            $dataUrlTree = $this->Link('tree');
            if (!empty($idArray)) {
                $dataUrlTree = Controller::join_links($dataUrlTree, '?forceValue='.implode(',', $idArray));
            }
        }
        $properties = array_merge(
            $properties,
            array(
                'Title' => $title,
                'EmptyTitle' => $emptyTitle,
                'Link' => $dataUrlTree,
                'Value' => $value
            )
        );
        return FormField::Field($properties);
    }

    /**
     * Save the results into the form
     * Calls function $record->onChange($items) before saving to the assummed
     * Component set.
     *
     * @param DataObjectInterface $record
     */
    public function saveInto(DataObjectInterface $record)
    {
        // Detect whether this field has actually been updated
        if ($this->value !== 'unchanged') {
            $items = array();

            $fieldName = $this->name;
            $saveDest = $record->$fieldName();
            if (!$saveDest) {
                user_error("TreeMultiselectField::saveInto() Field '$fieldName' not found on"
                    . " $record->class.$record->ID", E_USER_ERROR);
            }

            if ($this->value) {
                $items = preg_split("/ *, */", trim($this->value));
            }

            // Allows you to modify the items on your object before save
            $funcName = "onChange$fieldName";
            if ($record->hasMethod($funcName)) {
                $result = $record->$funcName($items);
                if (!$result) {
                    return;
                }
            }

            $saveDest->setByIDList($items);
        }
    }

    /**
     * Changes this field to the readonly field.
     */
    public function performReadonlyTransformation()
    {
        $copy = $this->castedCopy('SilverStripe\\Forms\\TreeMultiselectField_Readonly');
        $copy->setKeyField($this->keyField);
        $copy->setLabelField($this->labelField);
        $copy->setSourceObject($this->sourceObject);

        return $copy;
    }
}
