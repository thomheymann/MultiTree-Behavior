<?php
/**
 * Multi Tree Behaviour Class
 * 
 * MultiTree is a semi-drop-in behaviour to CakePHP's Core Tree Behavior allowing 
 * for more advanced operations and better performance on large data sets
 * 
 * NOTE: Use InnoDB (or a different engine that supports transactions, otherwise you have to LOCK tables manually during operations to prevent corrupted data in multi user environments)
 * 
 * @author Thomas Heymann
 * @link http://bakery.cakephp.org/articles/view/multitree-behavior
 * @link http://book.cakephp.org/view/228/Basic-Usage
 * @version	0.1
 * @license	http://www.opensource.org/licenses/mit-license.php The MIT License
 * @package app
 * @subpackage app.models.behaviors
 **/
class MultiTreeBehavior extends ModelBehavior {
	
	/**
	 * undocumented class variable
	 *
	 * @access protected
	 * @var array
	 **/
	var $_defaults = array(
		// Field names
		'parent' => 'parent_id',
		'left' => 'lft',
		'right' => 'rght',
		'root' => 'root_id', // optional, allow multiple trees per table
		'level' => 'level', // optional, cache levels
		
		// Other
		'recursive' => -1,
		
		// Private
		'__treeFields' => array(),
		'__move' => false,
		'__delete' => false
	);
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return void
	 **/
	function setup(&$Model, $config = array()) {
		// Merge config with defaults
		if ( !is_array($config) ) {
			$config = array();
		}
		$this->settings[$Model->alias] = array_merge($this->_defaults, $config);
		// __treeFields
		if ( empty($this->settings[$Model->alias]['__treeFields']) ) {
			$this->settings[$Model->alias]['__treeFields'] = array(
				$this->settings[$Model->alias]['parent'],
				$this->settings[$Model->alias]['left'],
				$this->settings[$Model->alias]['right']
				);
			if ( !empty($this->settings[$Model->alias]['root']) ) {
				// if ( !$Model->hasField($this->settings[$Model->alias]['root']) )
				$this->settings[$Model->alias]['__treeFields'][] = $this->settings[$Model->alias]['root'];
			}
			if ( !empty($this->settings[$Model->alias]['level']) ) {
				// if ( !$Model->hasField($this->settings[$Model->alias]['level']) )
				$this->settings[$Model->alias]['__treeFields'][] = $this->settings[$Model->alias]['level'];
			}
		}
	}

	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function beforeSave(&$Model) {
		extract($this->settings[$Model->alias]);
		
		// Are we about to create or edit?
		$creating = empty($Model->id);
		
		// Check if we need to perform changes to the tree
		if ( isset($Model->data[$Model->alias][$parent]) ) {
			// Get node
			if ( !$creating && ($node = $this->_node(&$Model, $Model->id)) === false ) {
				return false;
			}
			// Accept array with position information
			$position = 'lastChild';
			if ( is_array($Model->data[$Model->alias][$parent]) ) {
				if ( array_key_exists('destination', $Model->data[$Model->alias][$parent]) && array_key_exists('position', $Model->data[$Model->alias][$parent]) ) {
					$position = $Model->data[$Model->alias][$parent]['position'];
					$Model->data[$Model->alias][$parent] = $Model->data[$Model->alias][$parent]['destination'];
				} else {
					$Model->data[$Model->alias][$parent] = reset($Model->data[$Model->alias][$parent]);
				}
			}
			// Any parent changes?
			if ( $creating || $Model->data[$Model->alias][$parent] != $node[$parent] ) {
				// Check if parent axists
				if ( !empty($Model->data[$Model->alias][$parent]) && ($destNode = $this->_node(&$Model, $Model->data[$Model->alias][$parent])) === false ) {
					$Model->invalidate($parent, 'Parent does not exist');
					return false;
				}
				// Mark for moving
				$this->settings[$Model->alias]['__move'] = array('parent' => $Model->data[$Model->alias][$parent], 'position' => $position);
			}
		} else if ( !empty($root) && isset($Model->data[$Model->alias][$root]) ) {
			// Get node
			if ( !$creating && ($node = $this->_node(&$Model, $Model->id)) === false ) {
				return false;
			}
			// Any root changes?
			if ( $creating || $Model->data[$Model->alias][$root] != $node[$root] ) {
				// Mark for moving
				$this->settings[$Model->alias]['__move'] = array('root' => $Model->data[$Model->alias][$root]);
			}
		} else if ( $creating ) {
			$this->settings[$Model->alias]['__move'] = null;
		}
		
		// Don't allow manually changing left, right etc.
		$Model->data[$Model->alias] = array_diff_key($Model->data[$Model->alias], array_flip($__treeFields));
		
		return true;
	}
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function afterSave(&$Model, $created) {
		if ( $this->settings[$Model->alias]['__move'] !== false ) {
			$this->move($Model, $Model->id, $this->settings[$Model->alias]['__move']);
			$this->settings[$Model->alias]['__move'] = false;
		}
	}
	
	/**
	 * undocumented function
	 *
	 * @return void
	 **/
	function beforeDelete(&$Model, $cascade) {
		$this->settings[$Model->alias]['__delete'] = $this->_node($Model, $Model->id);
		return true;
	}
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function afterDelete(&$Model) {
		if ( $this->settings[$Model->alias]['__delete'] !== false ) {
			$this->removeFromTree($Model, $this->settings[$Model->alias]['__delete']);
			$this->settings[$Model->alias]['__delete'] = false;
		}
	}
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function move(&$Model, $id, $dest = null, $position = 'lastChild') {
		extract($this->settings[$Model->alias]);
		if ( !is_array($dest) ) {
			$dest = array('parent' => $dest);
		} else if ( array_key_exists('position', $dest) ) {
			$position = $dest['position'];
		}
		
		// Get node
		if ( ($node = $this->_node(&$Model, $id)) === false ) {
			return false;
		}
		$oldNode = $node;
		$invalid = (empty($oldNode[$left]) || empty($oldNode[$right]));
		
		// Start transaction
		$Model->getDataSource()->begin($Model);
		
		// Fake loop allowing us to jump to the end on failure
		while ( $commit = true ) {
		
			// Get node size
			if ( $invalid ) {
				$node[$left] = 1;
				$node[$right] = 2;
				// $node[$parent] = null;
				// if ( !empty($root) )
				// 	$node[$root] = null;
			}
			$treeSize = $node[$right]-$node[$left]+1;
			
			// Are we moving to another node?
			if ( !empty($dest['parent']) ) {
				// Get destination node
				if ( ($destNode = $this->_node(&$Model, $dest['parent'])) === false ) {
					// return false;
					$Model->invalidate($parent, 'Parent does not exist');
					$commit = false;
					break;
				}
				// Do not allow to move a node to or inside itself
				if ( !$invalid && (empty($root) || $node[$root] == $destNode[$root]) && ($destNode[$left] >= $node[$left] && $destNode[$right] <= $node[$right]) ) {
					// return false;
					$Model->invalidate($parent, 'Destination node is within source tree');
					$commit = false;
					break;
				}
				// Set beginning of shift range
				switch ( $position ) {
					case 'prevSibling':
						$node[$parent] = $destNode[$parent];
						if ( !empty($level) )
							$node[$level] = $destNode[$level];
						$start = $destNode[$left];
						break;
					case 'nextSibling':
						$node[$parent] = $destNode[$parent];
						if ( !empty($level) )
							$node[$level] = $destNode[$level];
						$start = $destNode[$right]+1;
						break;
					case 'firstChild':
						$node[$parent] = $destNode[$Model->primaryKey]; // Same as $dest['parent']
						if ( !empty($level) )
							$node[$level] = $destNode[$level]+1;
						$start = $destNode[$left]+1;
						break;
					case 'lastChild':
					default:
						$node[$parent] = $destNode[$Model->primaryKey]; // Same as $dest['parent']
						if ( !empty($level) )
							$node[$level] = $destNode[$level]+1;
						$start = $destNode[$right];
				}
				if ( !empty($root) )
					$node[$root] = $destNode[$root];
				
				// Create gap for node in target tree
				if ( ($commit = $this->_shift($Model, $start, $treeSize, @$destNode[$root])) === false )
					break;
				
				// Refresh node record (might have been affected by previous shift)
				// $node = $this->_node(&$Model, $id); // We can save us this query with the following:
				if ( ($affectedLeft = (!$invalid && (empty($root) || $node[$root] == $destNode[$root]) && $node[$left] >= $start)) !== false )
					$node[$left] += $treeSize;
				if ( ($affectedRight = (!$invalid && (empty($root) || $node[$root] == $destNode[$root]) && $node[$right] >= $start)) !== false )
					$node[$right] += $treeSize;
			} else if ( empty($root) ) {
				// Move to the end of new tree
				$node[$parent] = null;
				if ( !empty($level) )
					$node[$level] = 0;
				$start = $this->_max($Model, $right)+1;
			} else if ( !empty($dest['root']) ) {
				// Move to the end of tree
				$node[$root] = $dest['root'];
				$node[$parent] = null;
				if ( !empty($level) )
					$node[$level] = 0;
				$start = $this->_max($Model, $right, array($Model->escapeField($root) => $dest['root']))+1;
			} else if ( isset($dest['root']) && !empty($node[$root]) ) {
				// Move to the end of tree
				// $node[$root] = $node[$root]; // I know..
				$node[$parent] = null;
				if ( !empty($level) )
					$node[$level] = 0;
				$start = $this->_max($Model, $right, array($Model->escapeField($root) => $node[$root]))+1;
			} else {
				// Move to the end of new tree
				$node[$root] = $this->_max($Model, $root)+1;
				$node[$parent] = null;
				if ( !empty($level) )
					$node[$level] = 0;
				$start = 1;
			}
			
			if ( !$invalid && $treeSize > 2 ) {
				// Move node into that gap (Save new left, right, root and level)
				$diff = $start-$node[$left];
				$levelDiff = !empty($level) ? $node[$level]-$oldNode[$level] : 0;
				if ( ($commit = $this->_shiftRange($Model, $node[$left], $node[$right], $diff, @$oldNode[$root], @$node[$root], @$levelDiff)) === false )
					break;
				// Save new parent
				if ( ($commit = ($Model->save($node, array('callbacks' => false, 'validate' => false, 'fieldList' => array($parent))) !== false)) === false )
					break;
			} else {
				// Move node into that gap (Save new left, right, root, parent and level)
				$diff = $start-$node[$left];
				$data = $node; // Create new array, otherwise we affect range of shift() below
				$data[$left] += $diff;
				$data[$right] += $diff;
				if ( ($commit = ($Model->save($data, array('callbacks' => false, 'validate' => false, 'fieldList' => $__treeFields)) !== false)) === false )
					break;
			}
			
			// Remove gap created while removing node from source tree
			if ( !$invalid ) {
				if ( ($commit = $this->_shift($Model, $node[$left], -$treeSize, @$oldNode[$root])) === false )
					break;
			}
			
			// We don't want this to actually loop
			break;
		}
		
		// Commit
		if ( $commit ) {
			$Model->getDataSource()->commit($Model);
		} else {
			$Model->getDataSource()->rollback($Model);
		}
		return $commit;
	}
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function moveUp(&$Model, $id, $number = 1) {
		$prevSiblings = array_reverse($this->getPrevSiblings($Model, $id, false));
		if ( empty($prevSiblings) )
			return false;
		if ( count($prevSiblings) < $number )
			$number = count($prevSiblings);
		return $this->move($Model, $id, $prevSiblings[$number-1][$Model->alias][$Model->primaryKey], 'prevSibling');
	}
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function moveDown(&$Model, $id, $number = 1) {
		$nextSiblings = $this->getNextSiblings($Model, $id, false);
		if ( empty($nextSiblings) )
			return false;
		if ( count($nextSiblings) < $number )
			$number = count($nextSiblings);
		return $this->move($Model, $id, $nextSiblings[$number-1][$Model->alias][$Model->primaryKey], 'nextSibling');
	}
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function removeFromTree(&$Model, $id, $deleteChildren = true) {
		extract($this->settings[$Model->alias]);
		
		// Get node (or use id as data)
		if ( is_array($id) ) {
			$node = $id;
			$id = $node[$Model->primaryKey];
		} else {
			if ( ($node = $this->_node(&$Model, $id)) === false ) {
				return false;
			}
		}
		$invalid = (empty($node[$left]) || empty($node[$right]));
		if ( $invalid ) {
			// Delete invalid nodes just like that
			return $this->__delete($Model, $id);
		}
		
		// Get node size
		$treeSize = $node[$right]-$node[$left]+1;
		
		// Start transaction
		$Model->getDataSource()->begin($Model);
		
		// Fake loop allowing us to jump to the end on failure
		while ( $commit = true ) {
			// Either delete node and all its children - or - delete node and shift its children one level up
			if ( $deleteChildren ) {
				if ( $treeSize > 2 ) {
					// Delete node and all its children from tree
					if ( ($commit = $this->__deleteRange($Model, $node[$left], $node[$right], @$node[$root])) === false )
						break;
				} else {
					// Delete node
					if ( ($commit = $this->__delete($Model, $id)) === false )
						break;
				}
				// Remove gap created while removing node from tree
				if ( ($commit = $this->_shift($Model, $node[$left], -$treeSize, @$node[$root])) === false )
					break;
			} else {
				// Delete node
				if ( ($commit = $this->__delete($Model, $id)) === false )
					break;
				if ( $treeSize > 2 ) {
					// Set new parent of direct children
					$conditions = array($Model->escapeField($parent) => $id);
					if ( !empty($root) )
						$conditions[$Model->escapeField($root)] = $node[$root];
					if ( ($commit = $Model->updateAll(array($Model->escapeField($parent) => $node[$parent]), $conditions)) === false )
						break;
					// Shift all children up
					if ( ($commit = $this->_shiftRange($Model, $node[$left], $node[$right], -1, @$node[$root], @$node[$root], -1)) === false )
						break;
				}
				// Shift siblings
				if ( ($commit = $this->_shift($Model, $node[$right], -2, @$node[$root])) === false )
					break;
			}
			
			// We don't want this to actually loop
			break;
		}
			// $Model->getDataSource()->rollback($Model);
			// return true;
		
		// Commit
		if ( $commit ) {
			$Model->getDataSource()->commit($Model);
		} else {
			$Model->getDataSource()->rollback($Model);
		}
		return $commit;
	}
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function getChildren(&$Model, $id, $direct = false, $fields = null, $recursive = null) {
		extract($this->settings[$Model->alias]);
		
		if ( $direct ) {
			// Get node's direct children
			return $Model->find('all', array(
				'fields' => $fields,
				'conditions' => array($Model->escapeField($parent) => $id),
				'order' => array($Model->escapeField($left) => 'asc'),
				'recursive' => $recursive,
				));
		}
		
		// Get node
		if ( ($node = $this->_node($Model, $id)) === false ) {
			return array();
		}
		// Conditions
		$conditions = array(
			// $Model->escapeField($root) => $node[$root],
			$Model->escapeField($left).' >' => $node[$left],
			$Model->escapeField($right).' <' => $node[$right]
			);
		if ( !empty($root) )
			$conditions[$Model->escapeField($root)] = $node[$root];
		// Get node's children
		return $Model->find('all', array(
			'fields' => $fields,
			'conditions' => $conditions,
			'order' => array($Model->escapeField($left) => 'asc'),
			'recursive' => $recursive,
			));
	}
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function getChildCount(&$Model, $id, $direct = false) {
		extract($this->settings[$Model->alias]);
		
		if ( $direct ) {
			return $Model->find('count', array('conditions' => array($Model->escapeField($parent) => $id)));
		} else {
			// Use cached node if possible
			if ( isset($Model->data[$Model->alias][$left]) && isset($Model->data[$Model->alias][$right]) ) {
				$node = $Model->data[$Model->alias];
			} else {
				// Get node
				if ( ($node = $this->_node($Model, $id)) === false ) {
					return false;
				}
			}
			return ($node[$right]-$node[$left]-1)/2;
		}
	}

	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function getSiblings(&$Model, $id, $includeNode = false, $fields = null, $recursive = null) {
		extract($this->settings[$Model->alias]);
		
		// Get node
		if ( ($node = $this->_node($Model, $id)) === false ) {
			return array();
		}
		// Get node's siblings
		$conditions = array($Model->escapeField($parent) => $node[$parent]);
		if ( !$includeNode ) {
			$conditions[$Model->escapeField().' <>'] = $id;
		}
		return $Model->find('all', array(
			'fields' => $fields,
			'conditions' => $conditions,
			'order' => array($Model->escapeField($left) => 'asc'),
			'recursive' => $recursive,
			));
	}
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function getNextSiblings(&$Model, $id, $includeNode = false, $fields = null, $recursive = null) {
		extract($this->settings[$Model->alias]);
		
		// Get node
		if ( ($node = $this->_node($Model, $id)) === false ) {
			return array();
		}
		// Get node's siblings
		$conditions = array($Model->escapeField($parent) => $node[$parent]);
		if ( $includeNode ) {
			$conditions[$Model->escapeField($left).' >='] = $node[$left];
		} else {
			$conditions[$Model->escapeField($left).' >'] = $node[$left];
		}
		return $Model->find('all', array(
			'fields' => $fields,
			'conditions' => $conditions,
			'order' => array($Model->escapeField($left) => 'asc'),
			'recursive' => $recursive,
			));
	}
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function getPrevSiblings(&$Model, $id, $includeNode = false, $fields = null, $recursive = null) {
		extract($this->settings[$Model->alias]);
		
		// Get node
		if ( ($node = $this->_node($Model, $id)) === false ) {
			return array();
		}
		// Get node's siblings
		$conditions = array($Model->escapeField($parent) => $node[$parent]);
		if ( $includeNode ) {
			$conditions[$Model->escapeField($left).' <='] = $node[$left];
		} else {
			$conditions[$Model->escapeField($left).' <'] = $node[$left];
		}
		return $Model->find('all', array(
			'fields' => $fields,
			'conditions' => $conditions,
			'order' => array($Model->escapeField($left) => 'asc'),
			'recursive' => $recursive,
			));
	}
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function getNextSibling(&$Model, $id, $fields = null, $recursive = null) {
		extract($this->settings[$Model->alias]);
		
		// Get node
		if ( ($node = $this->_node($Model, $id)) === false ) {
			return false;
		}
		// Conditions
		$conditions = array(
			// $Model->escapeField($root) => $node[$root],
			$Model->escapeField($left) => $node[$right]+1
			);
		if ( !empty($root) )
			$conditions[$Model->escapeField($root)] = $node[$root];
		// Get node's parent
		return $Model->find('first', array(
			'fields' => $fields,
			'conditions' => $conditions,
			'recursive' => $recursive,
			));
	}
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function getPrevSibling(&$Model, $id, $fields = null, $recursive = null) {
		extract($this->settings[$Model->alias]);
		
		// Get node
		if ( ($node = $this->_node($Model, $id)) === false ) {
			return false;
		}
		// Conditions
		$conditions = array(
			// $Model->escapeField($root) => $node[$root],
			$Model->escapeField($right) => $node[$left]-1
			);
		if ( !empty($root) )
			$conditions[$Model->escapeField($root)] = $node[$root];
		// Get node's parent
		return $Model->find('first', array(
			'fields' => $fields,
			'conditions' => $conditions,
			'recursive' => $recursive,
			));
	}
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function getParent(&$Model, $id, $fields = null, $recursive = null) {
		extract($this->settings[$Model->alias]);
		
		// Get node
		if ( ($node = $this->_node($Model, $id)) === false ) {
			return false;
		}
		// Get node's parent
		return $Model->find('first', array(
			'fields' => $fields,
			'conditions' => array($Model->escapeField() => $node[$parent]),
			'recursive' => $recursive,
			));
	}
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function getParentFromTree(&$Model, $id, $fields = null, $recursive = null) {
		extract($this->settings[$Model->alias]);
		
		// Get node
		if ( ($node = $this->_node($Model, $id)) === false ) {
			return false;
		}
		// Conditions
		$conditions = array(
			$Model->escapeField($left).' <' => $node[$left],
			$Model->escapeField($right).' >' => $node[$right],
			);
		if ( !empty($root) )
			$conditions[$Model->escapeField($root)] = $node[$root];
		// Get path to node
		return $Model->find('first', array(
			'fields' => $fields,
			'conditions' => $conditions,
			'order' => array($Model->escapeField($left) => 'desc'),
			'recursive' => $recursive,
			));
	}
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function getPath(&$Model, $id, $fields = null, $recursive = null) {
		extract($this->settings[$Model->alias]);
		
		// Get node
		if ( ($node = $this->_node($Model, $id)) === false ) {
			return array();
		}
		// Conditions
		$conditions = array(
			// $Model->escapeField($root) => $node[$root],
			$Model->escapeField($left).' <=' => $node[$left],
			$Model->escapeField($right).' >=' => $node[$right],
			);
		if ( !empty($root) )
			$conditions[$Model->escapeField($root)] = $node[$root];
		// Get path to node
		return $Model->find('all', array(
			'fields' => $fields,
			'conditions' => $conditions,
			'order' => array($Model->escapeField($left) => 'asc'),
			'recursive' => $recursive,
			));
	}
	
	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function getLevel(&$Model, $id) {
		extract($this->settings[$Model->alias]);

		// Get node
		if ( ($node = $this->_node($Model, $id)) === false ) {
			return false;
		}
		// if ( !empty($level) )
		// 	return $node[$level];
		// Conditions
		$conditions = array(
			$Model->escapeField($left).' <' => $node[$left],
			$Model->escapeField($right).' >' => $node[$right],
			);
		if ( !empty($root) )
			$conditions[$Model->escapeField($root)] = $node[$root];		
		return $Model->find('count', array('conditions' => $conditions));
	}

	/**
	 * undocumented function
	 *
	 * @access public
	 * @return boolean
	 **/
	function generateTreeList(&$Model, $conditions = null, $keyPath = null, $valuePath = null, $spacer = '_', $recursive = null) {
		extract($this->settings[$Model->alias]);
		
		if ( is_numeric($conditions) ) {
			$results = $this->getChildren($Model, $conditions);
		} else {
			$order = array();
			if ( !empty($root) )
				$order[] = $Model->escapeField($root) => 'asc';
			$order[] = $Model->escapeField($left) => 'asc';
			$results = $Model->find('all', array(
				'conditions' => $conditions,
				'order' => $order,
				'recursive' => $recursive,
				));
		}
		if ( empty($results) ) {
			return array();
		}
		
		if ($keyPath == null && $valuePath == null && $Model->hasField($Model->displayField)) {
			$fields = array($Model->primaryKey, $Model->displayField, $root, $left, $right);
		} else {
			$fields = null;
		}
		if ($keyPath == null) {
			$keyPath = '{n}.'.$Model->alias.'.'.$Model->primaryKey;
		}
		if ($valuePath == null) {
			$valuePath = array('{0}{1}', '{n}.tree_prefix', '{n}.'.$Model->alias.'.'.$Model->displayField);
		} else if (is_string($valuePath)) {
			$valuePath = array('{0}{1}', '{n}.tree_prefix', $valuePath);
		} else {
			$valuePath[0] = '{'.(count($valuePath)-1).'}'.$valuePath[0];
			$valuePath[] = '{n}.tree_prefix';
		}
		
		if ( !empty($level) ) {
			foreach ( $results as $i => $result ) {
				$results[$i]['tree_prefix'] = str_repeat($spacer, $result[$Model->alias][$level]);
			}
		} else {
			foreach ($results as $i => $result) {
				$stack_key = @$result[$Model->alias][$root];
				if ( !@array_key_exists($stack_key, $stack) )
					$stack[$stack_key] = array();
				while ($stack[$stack_key] && ($stack[$stack_key][count($stack[$stack_key])-1] < $result[$Model->alias][$right])) {
					array_pop($stack[$stack_key]);
				}
				$results[$i]['tree_prefix'] = str_repeat($spacer,count($stack[$stack_key]));
				$stack[$stack_key][] = $result[$Model->alias][$right];
			}
		}
		return Set::combine($results, $keyPath, $valuePath);
		
	}
	
	/**
	 * undocumented function
	 *
	 * @return void
	 **/
	function repair(&$Model, $broken = 'tree') {
		extract($this->settings[$Model->alias]);
		$Model->recursive = $recursive;
		
		switch ( $broken ) {
			case 'parent':
				// Find and set parent of each node using tree structure
				$nodes = $Model->find('all', array(
					'fields' => array_merge(array($Model->primaryKey), $__treeFields),
					));
				foreach ( $nodes as $node ) {
					$id = $node[$Model->alias][$Model->primaryKey];
					if ( ($parentNode = $this->getParentFromTree($Model, $id)) !== false ) {
						$node[$Model->alias][$parent] = $parentNode[$Model->alias][$Model->primaryKey];
					} else {
						$node[$Model->alias][$parent] = null;
					}
					$Model->save($node, array('callbacks' => false, 'validate' => false, 'fieldList' => array($parent)));
				}
				break;
			
			case 'tree':
				// Null out all tree values except for parent
				$data = array_fill_keys(array_diff($__treeFields, array($parent)), null); // PHP5.2
				$Model->updateAll($data);
				// Move nodes back into tree structure, one after the other
				$nodes = $Model->find('all', array(
					'fields' => array_merge(array($Model->primaryKey), $__treeFields),
					'order' => array(
						"$Model->alias.$parent" => 'asc',
						"$Model->alias.$Model->primaryKey" => 'asc',
						)
					));
				foreach ( $nodes as $node ) {
					$node = reset($node);
					$this->move($Model, $node[$Model->primaryKey], $node[$parent], 'lastChild');
				}
				break;
		}
	}
	
	/**
	 * undocumented function
	 *
	 * @access protected
	 * @return void
	 **/
	function _node(&$Model, $id) {
		extract($this->settings[$Model->alias]);
		if ( ($node = $Model->find('first', array(
			'fields' => array_merge(array($Model->primaryKey), $__treeFields),
			'conditions' => array($Model->escapeField() => $id),
			'recursive' => $recursive
			))) === false ) {
			return false;
		}
		return reset($node);
	}
	
	/**
	 * undocumented function
	 *
	 * @access protected
	 * @return void
	 **/
	function _max(&$Model, $field, $conditions = null) {
		$max = $Model->find('all', array(
			'fields' => $Model->getDataSource()->calculate($Model, 'max', array($Model->escapeField($field), $field)),
			'conditions' => $conditions,
			'recursive' => -1
		));
		return (int)(reset(reset(reset($max))));
	}
	
	/**
	 * undocumented function
	 *
	 * @access protected
	 * @return void
	 **/
 	function _shift(&$Model, $first, $delta, $rootId = 1) {
		extract($this->settings[$Model->alias]);
		
		$sign = ($delta >= 0) ? ' + ' : ' - ';
		$delta = abs($delta);
		
		// Shift (left)
		$data = array($Model->escapeField($left) => $Model->escapeField($left).$sign.$delta);
		$conditions = array(
			// $Model->escapeField($root) => $rootId,
			$Model->escapeField($left).' >=' => $first,
			);
		if ( !empty($root) )
			$conditions[$Model->escapeField($root)] = $rootId;
		if ( $Model->updateAll($data, $conditions) === false )
			return false;
		
		// Shift (right)
		$data = array($Model->escapeField($right) => $Model->escapeField($right).$sign.$delta);
		$conditions = array(
			// $Model->escapeField($root) => $rootId,
			$Model->escapeField($right).' >=' => $first,
			);
		if ( !empty($root) )
			$conditions[$Model->escapeField($root)] = $rootId;
		if ( $Model->updateAll($data, $conditions) === false )
			return false;
		
		return true;
	}
	
	/**
	 * undocumented function
	 *
	 * @access protected
	 * @return void
	 **/
 	function _shiftRange(&$Model, $first, $last = 0, $delta, $rootId = 1, $destRootId = 1, $levelDelta = 0) {
		extract($this->settings[$Model->alias]);
		
		$sign = ($delta >= 0) ? ' + ' : ' - ';
		$delta = abs($delta);
		$levelSign = ($levelDelta >= 0) ? ' + ' : ' - ';
		$levelDelta = abs($levelDelta);
		
		// Data
		$data = array(
			// $Model->escapeField($root) => $destRootId,
			$Model->escapeField($left) => $Model->escapeField($left).$sign.$delta,
			$Model->escapeField($right) => $Model->escapeField($right).$sign.$delta
			);
		if ( !empty($root) )
			$data[$Model->escapeField($root)] = $destRootId;
		if ( !empty($level) )
			$data[$Model->escapeField($level)] = $Model->escapeField($level).$levelSign.$levelDelta;
		
		// Conditions
		$conditions = array(
			// $Model->escapeField($root) => $rootId,
			$Model->escapeField($left).' >=' => $first,
			$Model->escapeField($right).' <=' => $last,
			);
		if ( !empty($root) )
			$conditions[$Model->escapeField($root)] = $rootId;
		return $Model->updateAll($data, $conditions);
	}
	
	/**
	 * undocumented function
	 *
	 * @access private
	 * @return void
	 **/
	function __delete(&$Model, $id) {
		return $Model->deleteAll(array(
			$Model->escapeField() => $id
			), true, false);
	}
	
	/**
	 * undocumented function
	 *
	 * @access private
	 * @return void
	 **/
	function __deleteRange(&$Model, $first, $last, $rootId = 1) {
		extract($this->settings[$Model->alias]);
		
		$conditions = array(
			// $Model->escapeField($root) => $rootId,
			$Model->escapeField($left).' >=' => $first,
			$Model->escapeField($right).' <=' => $last
			);
		if ( !empty($root) )
			$conditions[$Model->escapeField($root)] = $rootId;
		return $Model->deleteAll($conditions, true, false);
	}


	/**
	 * @see getChildren
	 * @deprecated use getChildren
	 * @access public
	 */
	function children() {
		trigger_error('Deprecated method, use MultiTree::getChildren instead', E_USER_WARNING);
		return $this->getChildren();
	}

	/**
	 * @see repair
	 * @deprecated use repair
	 * @access public
	 */
	function recover(&$Model, $correct = 'parent', $missingParentAction = null) {
		trigger_error('Deprecated method, use MultiTree::repair instead', E_USER_WARNING);
		return $this->repair($Model, ($correct == 'parent' ? 'tree' : 'parent'));
	}
}
?>