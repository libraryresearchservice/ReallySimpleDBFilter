<?php

/*
 * (c) Library Research Service / Colorado State Library <LRS@lrs.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Lrs\ReallySimpleDBFilter

class ReallySimpleDBFilter {

	protected $allowed;
	protected $columns;
	protected $defaults;
	protected $queryString = array();
	protected $sorts = array();
	protected $strict = false;
	protected $validateColumns = true;
	protected $whereKey = 'filters';
	protected $wheres = array();
	
	public function __construct($columns, $queryString) {
		$this->reset()->columns($columns)->queryString($queryString)->wheres()->sorts();
	}
	
	public function columns($columns) {
		foreach ( $columns as $k => $v ) {
			$this->columns[$k] = array_merge($this->defaults['column'], $v);
		}
		return $this;
	}
	
	public function defaults($defaults = NULL, $overwrite = false) {
		if ( is_array($defaults) ) {
			if ( $overwrite === true ) {
				$this->defaults = $defaults;
			} else {
				$this->defaults = array_merge_recursive($this->defaults, $defaults);
			}
			return $this;
		}
		return $this->defaults;
	}

	public function filter($query) {
		if ( !$query instanceof \Illuminate\Database\Query\Builder && !$query instanceof \Illuminate\Database\Eloquent\Builder ) {
			die('The object passed to ReallySimpleDBFilter->filter() must be an instance of Laravel\'s DB or Eloquent classes.');
		}
		
		if ( $this->validateColumns ) {
			$this->strict(true);
			$schema = \Schema::setConnection($query->getModel()->getConnection());
			$tablesToGet = array();
			$columnKeyMap = array_map(
				function($v) {
					if ( isset($v['column']) && stristr($v['column'], '.') !== false ) {
						return $v['column'];
					}
				},
				$this->columns
			);
			foreach ( $columnKeyMap as $key => $column ) {
				$table = current(explode('.', $column));
				$tablesToGet[$table] = $table;
			}
			$tablesAndColumns = array();
			foreach ( $tablesToGet as $table => $columns ) {
				$columns = $schema->getColumnListing($table);
				if ( is_array($columns) ) {
					foreach ( $columns as $column ) {
						$tablesAndColumns[$table.'.'.$column] = $table.'.'.$column;
					}
				}
			}
			$valid = array_intersect($columnKeyMap, $tablesAndColumns);
			$this->columns = array_intersect_key($this->columns, $valid);
		}
		
		foreach ( $this->wheres as $where ) {	
			if ( $where['column'] == '' || ( $this->strict && !isset($this->columns[$where['column']]) ) ) {
				continue;
			}
			if ( isset($this->columns[$where['column']]) ) {
				$where['column'] = $this->columns[$where['column']]['column'];
			}
			if ( $where['boolean'] == 'or' ) {
				$builderFunction = 'orWhere';
			}  else {
				$builderFunction = 'where';
			}
			// WHERE column IN (x,y,z) [...HAVING COUNT(1) = z...]
			if ( is_array($where['value']) || $where['operator'] == 'in' || $where['operator'] == 'in (all)' ) {
				$subBuilderFunction = function($q) use ($query, $where) {
					if ( !is_array($where['value']) ) {
						$where['value'] = explode(',', $where['value']);
					}
					$q->whereIn($where['column'], $where['value']);
					if ( $where['operator'] == 'in (all)' ) {
						$query->having(new \Illuminate\Database\Query\Expression('COUNT(1)'), '=', sizeof($where['value']));
					}
				};
			// WHERE column [=, >, <, ...] 'value'
			} else if ( strstr(implode('', array_keys($this->defaults['operators']['comparison'])), $where['operator']) !== false ) {
				$subBuilderFunction = function($q) use ($where) {
					$q->where($where['column'], $where['operator'], $where['value']);
				};
			// WHERE IS NULL, WHERE IS NOT NULL
			} else if ( strstr(implode('', array_keys($this->defaults['operators']['null'])), $where['operator']) !== false ) {
				$subBuilderFunction = function($q) use ($where) {
					$q->{$where['operator']}($where['column'], $where['operator'], $where['value']);
				};
			// WHERE LIKE '%value%'
			} else if ( strstr(implode('', array_keys($this->defaults['operators']['keyword'])), $where['operator']) !== false ) {
				$subBuilderFunction = function($q) use ($where) {
					$q->where($where['column'], $where['operator'], '%'.$where['value'].'%');
				};
			}
			// Use Laravel subquery to allow and/or between individual WHERE clauses.
			// Eventually will allow for automatically grouping clauses
			if ( isset($subBuilderFunction) ) {
				// Callback before
				if ( is_callable($where['before']) ) {
					$where['before']($query);
				}
				$query->{$builderFunction}($subBuilderFunction);
				// Callback after
				if ( is_callable($where['after']) ) {
					$where['after']($query);
				}
			}
		}
		if ( isset($this->sorts['orderBy']) && is_array($this->sorts['orderBy']) ) {
			$this->sorts['orderBy'] = array_filter($this->sorts['orderBy']);
			foreach ( $this->sorts['orderBy'] as $k => $column ) {
				if ( $this->strict && !isset($this->columns[$column]) ) {
					continue;
				}
				$order = $this->defaults['order'];
				if ( isset($this->columns[$column]) ) {
					$column = $this->columns[$column]['column'];
				}
				if ( isset($this->sorts['order'][$k]) && in_array($this->sorts['order'][$k], $this->allowed['orders']) ) {
					$order = $this->sorts['order'][$k];
				}
				$query->orderBy($column, $order);
			}
		}
		return $query;
	}

	public function queryString($queryString) {
		if ( is_array($queryString) ) {
			$this->queryString = $queryString;
		} else {
			parse_str($queryString, $this->queryString);
		}
		return $this;
	}

	public function sorts() {
		foreach ( $this->queryString as $k => $v ) {
			if ( $k == $this->defaults['orderByKey'] || $k == $this->defaults['orderKey'] ) {
				if ( !is_array($v) ) {
					$v = array($v);
				}
				$this->sorts[$k] = $v;
			}
		}
		return $this;
	}

	public function reset() {
		$this->allowed = array(
			'orders' => array(
				'asc',
				'desc',
			),
		);
		$this->defaults = array(
			'column' => array(
				'alias'		=> false,
				'column' 	=> false,
				'validated'	=> NULL,
			),
			'operators' => array(
				'comparison' => array(
					'='		=> '=',
					'!='	=> '!=',
					'<>'	=> '<>',
					'>'		=> '>',
					'>='	=> '>=',
					'<'		=> '<',
					'<='	=> '<=',
					'in'	=> 'whereIn',
				),
				'keyword' => array(
					'like'		=> 'like',
					'notLike'	=> 'not like',
				),
				'logical' => array(
					'and' 	=> 'and',
					'or'	=> 'or',
				),
				'null' => array(
					'null'		=> 'whereNull',
					'notNull'	=> 'whereNotNull',
				),
			),
			'order' => 'asc',
			'orderKey' => 'order',
			'orderByKey' => 'orderBy',
			'where' => array(
				'after'		=> false,
				'before'	=> false,
				'boolean'	=> 'and',
				'builder'	=> false,
				'column'	=> false,
				'operator'	=> '=',
				'value'		=> false,
			)
		);
		return $this;
	}

	/**
	 *	If $this->strict = true, a column is only filterable/sortable if present in $this->columns via
	 *	user configuration.
	 */
	public function strict($strict) {
		$this->strict = is_bool($strict) ? $strict : false;
		return $this;
	}
	
	public function validColumns() {
		
	}
	
	public function validateColumns($validateColumns) {
		$this->validateColumns = is_bool($validateColumns) ? $validateColumns : false;
		return $this;
	}

	public function wheres() {
		if ( isset($this->queryString[$this->whereKey]) ) {
			$wheres = $this->queryString[$this->whereKey];
		} else {
			$wheres = false;
		}
		if ( is_array($wheres) ) {
			foreach ( $wheres as $where ) {
				if ( !is_array($where) ) {
					$where = array($where);
				}
				array_push($this->wheres, array_merge($this->defaults['where'], $where));
			}
		}
		return $this;
	}
	
}
