<?php

namespace SilverStripe\UserDocs\Search;

use InvalidArgumentException;
use SilverStripe\Control\RequestHandler;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\Search\SearchContextForm;
use SilverStripe\View\SSViewer;

/**
 * Search form which conducts a fulltext search on all documentation pages using teamtnt/tntsearch
 */
class SearchForm extends Form
{
    public const string DEFAULT_SEARCH_FIELD = 'terms';

    private static $casting = [
        'SchemaData' => 'Text',
        'getSchemaData' => 'Text',
    ];

    public function __construct(RequestHandler $controller, ?FieldList $fields = null, $name = 'SearchForm')
    {
        $searchFields = $fields ?? FieldList::create([
            HiddenField::create(SearchForm::DEFAULT_SEARCH_FIELD, _t(__CLASS__ . '.SearchTerms', 'Search Terms')),
            TextField::create('title', _t(__CLASS__ . '.Title', 'Title')),
        ]);
        $this->prepareSearchFieldsForCMS($searchFields);
        parent::__construct($controller, $name, $searchFields);
        $this->setHTMLID(ClassInfo::shortName($controller) . '_' . $name);
        // This form is not tied to session so we disable the CSRF token
        $this->disableSecurityToken();
        $this->setFormMethod('GET');
        $this->setFormAction($controller->Link());
        $this->addExtraClass('cms-search-form');
    }

    public function getSearchParams(): array
    {
        $params = $this->getController()->getRequest()->getVar('q');
        return is_array($params) ? $params : [];
    }

    /**
     * Get the schema for this search context for use in the CMS search filter form.
     * Note that this is not the same as the form schema (i.e. it includes no information about form fields).
     */
    public function getSchemaData(): array
    {
        $formSchemaUrl = $this->getRequestHandler()->Link('schema');

        // Prefix "Search__" onto the search params to match the field names in the actual form
        $searchParams = $this->getSearchParams();
        if (!empty($searchParams)) {
            $searchParams = array_combine(array_map(function ($key) {
                return 'Search__' . $key;
            }, array_keys($searchParams ?? [])), $searchParams ?? []);
        }

        return [
            'formSchemaUrl' => $formSchemaUrl,
            'name' => SearchForm::DEFAULT_SEARCH_FIELD,
            // stdClass will map to empty json object '{}' when encoded
            'filters' => $searchParams ?: new \stdClass(),
            'placeholder' => _t(__CLASS__ . '.FilterLabelText', 'Search documentation'),
        ];
    }

    /**
     * Get the rendered placeholder with schema data required for generating the search filter form in the CMS
     *
     * @param string $idSuffix Appended to the HTML ID. Use a different suffix for each placeholder on the same page.
     * The filter button for this placeholder must use the same suffix.
     */
    public function getPlaceHolder(bool $isFiltered, string $idSuffix = ''): DBHTMLText
    {
        $templateCandidates = array_merge(
            SSViewer::get_templates_by_class(SearchContextForm::class, '_Placeholder', Form::class),
            SSViewer::get_templates_by_class(static::class, '_Placeholder', __CLASS__)
        );
        return $this->renderWith($templateCandidates, $this->getCMSTemplateData($isFiltered, $idSuffix));
    }

    /**
     * Get the rendered search filter button which toggles display of the search filter form in the CMS
     *
     * @param string $idSuffix Appended to the HTML ID of the placeholder this button controls.
     * Use the same suffix as that placeholder.
     */
    public function getFilterButton(bool $isFiltered, string $idSuffix = ''): DBHTMLText
    {
        $templateCandidates = array_merge(
            SSViewer::get_templates_by_class(SearchContextForm::class, '_Button', Form::class),
            SSViewer::get_templates_by_class(static::class, '_Button', __CLASS__)
        );
        return $this->renderWith($templateCandidates, $this->getCMSTemplateData($isFiltered, $idSuffix));
    }

    private function getCMSTemplateData(bool $isFiltered, string $idSuffix): array
    {
        // The ID is used in a jQuery selector, so only allow characters that are safe there
        if (!preg_match('/^[A-Za-z0-9_-]*\z/', $idSuffix)) {
            throw new InvalidArgumentException('$idSuffix can only contain letters, numbers, underscores and hyphens');
        }
        return [
            'IsFiltered' => $isFiltered,
            'HTMLID' => $this->getHTMLID() . $idSuffix,
        ];
    }

    /**
     * Prepares search fields for use in the CMS.
     *
     * - Appends a prefix to search field names to prevent conflicts with other fields in the search form
     * - Adds appropriate CSS classes
     */
    private function prepareSearchFieldsForCMS(FieldList $fields): void
    {
        foreach ($fields as $field) {
            $field->addExtraClass('stacked');
            $field->setName('Search__' . $field->getName());
            if ($field instanceof CompositeField) {
                $this->prepareSearchFieldsForCMS($field->getChildren());
            }
        }
    }
}
