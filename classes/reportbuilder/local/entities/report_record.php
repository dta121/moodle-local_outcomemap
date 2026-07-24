<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_outcomemap\reportbuilder\local\entities;

use coding_exception;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use lang_string;

/**
 * Configurable local entity used by the governed reporting sources.
 *
 * Source classes retain ownership of joins and row grain. This entity keeps
 * column/filter construction consistent and gives each element the same
 * inherited joins without performing any row callbacks that query data.
 *
 * @package    local_outcomemap
 * @copyright  2026 Moodle Learning Outcome Mapping contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_record extends base {
    /** @var string[] Database tables used by the entity. */
    private array $tables;

    /** @var lang_string Entity title. */
    private lang_string $title;

    /** @var array[] Column definitions. */
    private array $columndefinitions = [];

    /** @var array[] Filter definitions. */
    private array $filterdefinitions = [];

    /**
     * Constructor.
     *
     * @param string[] $tables Database tables used by this entity.
     * @param lang_string $title Entity title.
     * @param string $entityname Stable element namespace.
     */
    public function __construct(array $tables, lang_string $title, string $entityname = 'outcomemap') {
        if ($tables === []) {
            throw new coding_exception('A report entity must declare at least one table.');
        }

        $this->tables = array_values(array_unique($tables));
        $this->title = $title;
        $this->set_entity_name($entityname);
    }

    /**
     * Database tables used by this entity.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return $this->tables;
    }

    /**
     * Entity title.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return $this->title;
    }

    /**
     * Define one report column.
     *
     * Associative field keys are used as explicit aliases. Numeric keys are
     * passed to Report Builder for simple-field alias detection.
     *
     * @param string $name Internal column name.
     * @param lang_string|null $title Display title.
     * @param int $type One of column::TYPE_*.
     * @param array $fields SQL fields, optionally keyed by aliases.
     * @param bool $sortable Whether the column is sortable.
     * @param callable|null $callback Formatting callback with no DML.
     * @param string[] $sortfields Fields/aliases used for sorting.
     * @param bool $disableaggregation Disable all Report Builder aggregation.
     * @return self
     */
    public function define_column(
        string $name,
        ?lang_string $title,
        int $type,
        array $fields,
        bool $sortable = true,
        ?callable $callback = null,
        array $sortfields = [],
        bool $disableaggregation = false
    ): self {
        if ($fields === []) {
            throw new coding_exception("The {$name} report column must define at least one field.");
        }

        $this->columndefinitions[] = [
            'name' => $name,
            'title' => $title,
            'type' => $type,
            'fields' => $fields,
            'sortable' => $sortable,
            'callback' => $callback,
            'sortfields' => $sortfields,
            'disableaggregation' => $disableaggregation,
        ];

        return $this;
    }

    /**
     * Define one filter and, by default, the corresponding condition.
     *
     * @param string $name Internal filter name.
     * @param lang_string $title Display title.
     * @param string $filterclass Report Builder filter class.
     * @param string $fieldsql SQL expression being filtered.
     * @param mixed $options Static options or a no-argument options callback.
     * @param bool $condition Also expose the filter as a condition.
     * @return self
     */
    public function define_filter(
        string $name,
        lang_string $title,
        string $filterclass,
        string $fieldsql,
        $options = null,
        bool $condition = true
    ): self {
        $this->filterdefinitions[] = [
            'name' => $name,
            'title' => $title,
            'filterclass' => $filterclass,
            'fieldsql' => $fieldsql,
            'options' => $options,
            'condition' => $condition,
        ];

        return $this;
    }

    /**
     * Build configured columns, filters, and conditions.
     *
     * @return base
     */
    public function initialise(): base {
        foreach ($this->columndefinitions as $definition) {
            $reportcolumn = (new column(
                $definition['name'],
                $definition['title'],
                $this->get_entity_name()
            ))
                ->set_type($definition['type'])
                ->set_is_sortable($definition['sortable'], $definition['sortfields'])
                ->add_joins($this->get_joins());

            foreach ($definition['fields'] as $alias => $fieldsql) {
                if (is_int($alias)) {
                    $reportcolumn->add_field($fieldsql);
                } else {
                    $reportcolumn->add_field($fieldsql, $alias);
                }
            }
            if ($definition['callback'] !== null) {
                $reportcolumn->add_callback($definition['callback']);
            }
            if ($definition['disableaggregation']) {
                $reportcolumn->set_disabled_aggregation_all();
            }
            $this->add_column($reportcolumn);
        }

        foreach ($this->filterdefinitions as $definition) {
            $reportfilter = (new filter(
                $definition['filterclass'],
                $definition['name'],
                $definition['title'],
                $this->get_entity_name(),
                $definition['fieldsql']
            ))->add_joins($this->get_joins());

            if ($definition['options'] !== null) {
                if (is_callable($definition['options'])) {
                    $reportfilter->set_options_callback($definition['options']);
                } else {
                    $reportfilter->set_options($definition['options']);
                }
            }

            $this->add_filter($reportfilter);
            if ($definition['condition']) {
                $this->add_condition($reportfilter);
            }
        }

        return $this;
    }
}
