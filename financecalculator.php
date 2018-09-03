<?php
/**
 * @copyright   Copyright (C) 2018 Blue Flame Digtial Solutions Limited. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

use Joomla\CMS\Document\HtmlDocument;

defined('_JEXEC') or die;

/**
 * Class PlgContentFinancecalculator
 */
class PlgContentFinancecalculator extends JPlugin
{
    const _EAV_MILAGE = 4;
    const _EAV_REGISTRATION = 11;
    /**
     * @var string The mambot code placeholder
     */
    protected $regex = '#\{finance_calculator\}#iU';

    /**
     * Database object
     *
     * @var    JDatabaseDriver
     * @since  3.3
     */
    protected $db;

    /**
     * Prepare the text with replacements, only if needed, for performance purposes
     *
     * @param $context
     * @param $row
     * @param $params
     * @param int $page
     *
     * @return bool
     */
    public function onContentPrepare($context, &$row, $params, $page = 0)
    {
        try {

            // Only process when we are in a task that requires it (E.g on a Car view)
            if (!in_array($context, array('text'))) {
                throw new Exception('Wrong Context');
            }

            // Only run if we actually need to
            if (!strpos($row->text, '{finance_calculator}')) {
                return true; // nothing to replace
            }

            // get the card ID from the URL provided
            $carId = JFactory::getApplication()->input->getString('id');

            // ensure that we can actually process this car id, might have been cached url in google or faked
            if (!strpos($carId, ':')) {
                throw new Exception('No Car Slug Explodeable');
            } else {
                // explode the slugs to get a valid identifier
                list($carId, $slug) = explode(':', $carId);

                // ensure we get an integer
                $carId = intval($carId);
                if ( !is_integer($carId) || null === $carId) {
                    throw new Exception('No Car Id');
                }
            }

            // ensure we can convert that to a car details object
            if (!$carDetails = $this->getCar($carId)) {
                throw new Exception('No Car Details');
            }

            // do the actual JS inkection
            $row = $this->injectJSForCar($row, $carDetails);

        } catch (Exception $exception) {
            // if something goes wrong, remove the mambot and embed an error in the source
            $row->text = preg_replace($this->regex, '<!-- ' . $exception->getMessage() . ' -->', $row->text);
        }

        // because of we dont - it moans :)
        return true;
    }


    /**
     * @param int $id
     *
     * @return mixed
     */
    private function getCar(int $id)
    {
        $this->db->setQuery("SELECT * FROM #__djcf_items WHERE id='" . (int)$id . "' LIMIT 1");
        return $this->db->loadObject();

    }

    /**
     * @param stdClass $row The row object to process, we are interested in $row->text
     * @param stdClass $carDetails The car details from the database
     *
     * @return stdClass The row object to process, we are interested in $row->text
     */
    private function injectJSForCar(stdClass $row, stdClass $carDetails)
    {
        $this->setupJS($carDetails);
        $row->text = preg_replace($this->regex, '<div id="pluginContent' . $carDetails->id . '"></div>', $row->text);

        return $row;
    }

    /**
     * Set up the library once per page and output a function to run on domready for each car, avoiding conflicts and
     * double includes.
     *
     * @param stdClass $data The car details from the database
     *
     * @return void
     */
    private function setupJS(stdClass $data)
    {
        /** @var HtmlDocument $document the Joomla output document */
        $document = JFactory::getDocument();

        // Only inject the required library once per page load
        if (!defined('_CODEWEAVERS_JS_INCLUDED')) {
            $document->addScript('https://plugins.codeweavers.net/scripts/v1/codeweavers/finance?ApiKey=FGi0HbA4aeiG4577e1');
            define('_CODEWEAVERS_JS_INCLUDED', 1);
        }

        // inject the finance calculator, pre configured, once per car, avoiding conflicts
        $js = "window.addEvent('domready', function() {";
        $js .= sprintf("
                try {
                    function loadPlugin%s() {
                        codeweavers.main({
                            pluginContentDivId: 'pluginContent%s',
                            vehicle: {
                                type: 'Car', 
                                identifier: '%s', 
                                identifierType: 'CAPSHORTCODE',
                                isNew: false, 
                                cashPrice: '%s', 
                                mileage: '%s',
                                imageUrl: '%s',
                                linkBackUrl: '%s', 
                                registration: {
                                    number: '%s'
                                },
                            }
                        });
                    }
                    loadPlugin%s();
                } catch(e){
                    // Catch any errors silently
                }
            ",
            $data->id,
            $data->id,
            $data->id,
            $data->price,
            $this->getMilageForCarId($data->id),
            $this->getImageForCarId($data->id),
            JUri::current(),
            $this->getRegistrationForCarId($data->id),
            $data->id
        );

        $js .= '  });';

        $document->addScriptDeclaration($js);
    }

    /**
     * Get the milage from a different table usin EAV schema
     *
     * @param integer $id the car id
     *
     * @return string the car milage
     */
    private function getMilageForCarId($id)
    {
        $this->db->setQuery("SELECT value FROM #__djcf_fields_values WHERE field_id = " . self::_EAV_MILAGE . " AND item_id='" . (int)$id . "' LIMIT 1");
        return $this->db->loadResult();
    }

    /**
     * Get the image from a different table usin EAV schema
     *
     * @param integer $id the car id
     *
     * @return string the car image absolute url
     */
    private function getImageForCarId($id)
    {
        $this->db->setQuery("SELECT * FROM #__djcf_images WHERE item_id='" . (int)$id . "' ORDER BY ordering ASC LIMIT 1");
        $row = $this->db->loadObject();

        // replace incorrect double //
        return JUri::base() . substr($row->path, 1, strlen($row->path) - 1) . $row->name . '.' . $row->ext;
    }

    /**
     * Get the registration from a different table usin EAV schema
     *
     * @param integer $id the car id
     *
     * @return string the car registration
     */
    private function getRegistrationForCarId($id)
    {
        $this->db->setQuery("SELECT value FROM #__djcf_fields_values WHERE field_id = " . self::_EAV_REGISTRATION . " AND item_id='" . (int)$id . "' LIMIT 1");
        return $this->db->loadResult();
    }
}