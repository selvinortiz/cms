<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\controllers;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\db\CategoryQuery;
use craft\errors\InvalidTypeException;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The ElementsController class is a controller that handles various element related actions including retrieving and
 * saving element and their corresponding HTML.
 *
 * Note that all actions in the controller require an authenticated Craft session via [[Controller::allowAnonymous]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class ElementsController extends BaseElementsController
{
    // Public Methods
    // =========================================================================

    /**
     * Renders and returns the body of an ElementSelectorModal.
     *
     * @return Response
     */
    public function actionGetModalBody(): Response
    {
        $sourceKeys = Craft::$app->getRequest()->getParam('sources');
        $elementType = $this->elementType();
        $context = $this->context();

        $showSiteMenu = Craft::$app->getRequest()->getParam('showSiteMenu', 'auto');

        if ($showSiteMenu !== 'auto') {
            $showSiteMenu = (bool)$showSiteMenu;
        }

        if (is_array($sourceKeys)) {
            $sources = [];

            foreach ($sourceKeys as $key) {
                $source = ElementHelper::findSource($elementType, $key, $context);

                if ($source !== null) {
                    $sources[$key] = $source;
                }
            }
        } else {
            $sources = Craft::$app->getElementIndexes()->getSources($elementType);
        }

        if (!empty($sources) && count($sources) === 1) {
            $firstSource = reset($sources);
            $showSidebar = !empty($firstSource['nested']);
        } else {
            $showSidebar = !empty($sources);
        }

        return $this->asJson([
            'html' => $this->getView()->renderTemplate('_elements/modalbody', [
                'context' => $context,
                'elementType' => $elementType,
                'sources' => $sources,
                'showSidebar' => $showSidebar,
                'showSiteMenu' => $showSiteMenu,
            ])
        ]);
    }

    /**
     * Returns the HTML for an element editor HUD.
     *
     * @return Response
     * @throws NotFoundHttpException if the requested element cannot be found
     * @throws ForbiddenHttpException if the user is not permitted to edit the requested element
     */
    public function actionGetEditorHtml(): Response
    {
        $element = $this->_getEditorElement();
        $includeSites = (bool)Craft::$app->getRequest()->getBodyParam('includeSites', false);

        return $this->_getEditorHtmlResponse($element, $includeSites);
    }

    /**
     * Saves an element.
     *
     * @return Response
     * @throws NotFoundHttpException if the requested element cannot be found
     * @throws ForbiddenHttpException if the user is not permitted to edit the requested element
     */
    public function actionSaveElement(): Response
    {
        /** @var Element $element */
        $element = $this->_getEditorElement();
        $namespace = Craft::$app->getRequest()->getRequiredBodyParam('namespace');
        $params = Craft::$app->getRequest()->getBodyParam($namespace, []);
        $element->setFieldParamNamespace($namespace.'.fields');

        if (isset($params['fields'])) {
            $fields = $params['fields'];
            $element->setFieldValues($fields);
            unset($params['fields']);
        }

        Craft::configure($element, $params);

        // Either way, at least tell the element where its content comes from
        $element->setFieldValuesFromRequest($namespace.'.fields');

        // Now save it
        if (Craft::$app->getElements()->saveElement($element)) {
            $response = [
                'success' => true,
                'id' => $element->id,
                'siteId' => $element->siteId,
                'newTitle' => (string)$element,
                'cpEditUrl' => $element->getCpEditUrl(),
            ];

            // Should we be including table attributes too?
            $sourceKey = Craft::$app->getRequest()->getBodyParam('includeTableAttributesForSource');

            if ($sourceKey) {
                $attributes = Craft::$app->getElementIndexes()->getTableAttributes(get_class($element), $sourceKey);

                // Drop the first one
                array_shift($attributes);

                foreach ($attributes as $attribute) {
                    $response['tableAttributes'][$attribute[0]] = $element->getTableAttributeHtml($attribute[0]);
                }
            }

            return $this->asJson($response);
        }

        return $this->_getEditorHtmlResponse($element, false);
    }

    /**
     * Returns the HTML for a Categories field input, based on a given list of selected category IDs.
     *
     * @return Response
     */
    public function actionGetCategoriesInputHtml(): Response
    {
        $request = Craft::$app->getRequest();
        $categoryIds = $request->getParam('categoryIds', []);

        // Fill in the gaps
        $categoryIds = Craft::$app->getCategories()->fillGapsInCategoryIds($categoryIds);

        if (!empty($categoryIds)) {
            /** @var CategoryQuery $categoryQuery */
            $categoryQuery = Category::find()
                ->id($categoryIds)
                ->siteId($request->getParam('siteId'))
                ->status(null)
                ->enabledForSite(false)
                ->limit($request->getParam('limit'));
            $categories = $categoryQuery->all();
        } else {
            $categories = [];
        }

        $html = $this->getView()->renderTemplate('_components/fieldtypes/Categories/input',
            [
                'elements' => $categories,
                'id' => $request->getParam('id'),
                'name' => $request->getParam('name'),
                'selectionLabel' => $request->getParam('selectionLabel'),
            ]);

        return $this->asJson([
            'html' => $html,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the element that is currently being edited.
     *
     * @return ElementInterface
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     */
    private function _getEditorElement(): ElementInterface
    {
        $request = Craft::$app->getRequest();
        $elementsService = Craft::$app->getElements();

        $elementId = $request->getBodyParam('elementId');
        $siteId = $request->getBodyParam('siteId') ?: Craft::$app->getSites()->currentSite->id;

        // Determine the element type
        $elementType = $request->getBodyParam('elementType');

        if ($elementType === null && $elementId !== null) {
            $elementType = $elementsService->getElementTypeById($elementId);
        }

        if ($elementType === null) {
            throw new BadRequestHttpException('Request missing required body param');
        }

        // Make sure it's a valid element type
        // TODO: should probably move the code inside try{} to a helper method
        try {
            if (!is_subclass_of($elementType, ElementInterface::class)) {
                throw new InvalidTypeException($elementType, ElementInterface::class);
            }
        } catch (InvalidTypeException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        // Instantiate the element
        if ($elementId !== null) {
            $element = $elementsService->getElementById($elementId, $elementType, $siteId);

            if (!$element) {
                throw new BadRequestHttpException('No element exists with the ID '.$elementId);
            }
        } else {
            $element = new $elementType();
        }

        /** @var Element $element */
        // Make sure the user is allowed to edit this site
        $userService = Craft::$app->getUser();
        if (Craft::$app->getIsMultiSite() && $elementType::isLocalized() && !$userService->checkPermission('editSite:'.$element->siteId)) {
            // Find the first site the user does have permission to edit
            $elementSiteIds = [];
            $newSiteId = null;

            foreach (ElementHelper::supportedSitesForElement($element) as $siteInfo) {
                $elementSiteIds[] = $siteInfo['siteId'];
            }

            foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
                if (in_array($siteId, $elementSiteIds, false) && $userService->checkPermission('editSite:'.$siteId)) {
                    $newSiteId = $siteId;
                    break;
                }
            }

            if ($newSiteId === null) {
                // Couldn't find an editable site supported by the element
                throw new ForbiddenHttpException('The user doesn’t have permission to edit this element');
            }

            // Apply the new site
            $siteId = $newSiteId;

            if ($elementId !== null) {
                $element = $elementsService->getElementById($elementId, $elementType, $siteId);
            } else {
                $element->siteId = $siteId;
            }
        }

        // Populate it with any posted attributes
        $attributes = $request->getBodyParam('attributes', []);
        $attributes['siteId'] = $siteId;
        Craft::configure($element, $attributes);

        // Make sure it's editable
        // (ElementHelper::isElementEditable() is overkill here since we've already verified the user can edit the element's site)
        if (!$element->getIsEditable()) {
            throw new ForbiddenHttpException('The user doesn’t have permission to edit this element');
        }

        return $element;
    }

    /**
     * Returns the editor HTML response for a given element.
     *
     * @param ElementInterface $element
     * @param bool             $includeSites
     *
     * @return Response
     * @throws ForbiddenHttpException if the user is not permitted to edit content in any of the sites supported by this element
     */
    private function _getEditorHtmlResponse(ElementInterface $element, bool $includeSites): Response
    {
        /** @var Element $element */
        $siteIds = ElementHelper::editableSiteIdsForElement($element);

        if (empty($siteIds)) {
            throw new ForbiddenHttpException('User not permitted to edit content in any of the sites supported by this element');
        }

        $response = [];

        if ($includeSites) {
            if (count($siteIds) > 1) {
                $response['siteIds'] = [];

                foreach ($siteIds as $siteId) {
                    $site = Craft::$app->getSites()->getSiteById($siteId);

                    $response['sites'][] = [
                        'id' => $siteId,
                        'name' => Craft::t('site', $site->name),
                    ];
                }
            } else {
                $response['sites'] = null;
            }
        }

        $response['siteId'] = $element->siteId;

        $namespace = 'editor_'.StringHelper::randomString(10);
        $this->getView()->setNamespace($namespace);

        $response['html'] = '<input type="hidden" name="namespace" value="'.$namespace.'">';

        if ($element->id !== null) {
            $response['html'] .= '<input type="hidden" name="elementId" value="'.$element->id.'">';
        }

        if ($element->siteId !== null) {
            $response['html'] .= '<input type="hidden" name="siteId" value="'.$element->siteId.'">';
        }

        $response['html'] .= '<div class="meta">'.
            $this->getView()->namespaceInputs((string)$element->getEditorHtml()).
            '</div>';

        $view = $this->getView();
        $response['headHtml'] = $view->getHeadHtml();
        $response['footHtml'] = $view->getBodyHtml();

        return $this->asJson($response);
    }
}
