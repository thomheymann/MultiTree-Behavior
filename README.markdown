# CakePHP MultiTree Behavior

[MultiTree][1] is a drop-in behaviour to CakePHP's Core [Tree Behavior][2] allowing for more advanced operations and better performance on large data sets

## Advantages
* Support for root_id (This will vastly increase speed for write operations on large data sets - this is because not the whole tree has to be rewritten when updating a node but only those rows with the same root id)
* Support for level caching
* Easier moving of nodes (MultiTree supports full move() to any id as opposed to Core Tree's moveUp and moveDown)
* More getter functions (easily retrieve siblings, children, parents etc.)

## Caution
Use __InnoDB__ (or a different engine that supports transactions, otherwise you have to LOCK tables manually during operations to prevent corrupted data in multi user environments)

## Configuration
### Example 1
The following config is meant for large trees that are often updated as well a retrieved. It keeps track of a tree that has root_id's and level caching enabled. It is ideal for e.g. Comment Trees

	class Comment extends AppModel {
		var $name = 'Comment';
		var $actsAs = array(
			'MultiTree' => array(
				'root' =>'root_id',
				'level' =>'level'
				)
			);
	}

#### Schema:

	CREATE TABLE `comments` (
	  `id` int(10) unsigned NOT NULL auto_increment,
	  `title` varchar(128) NOT NULL default '',
	  `body` text NOT NULL,
	  `created` datetime default NULL,
	  `modified` datetime default NULL,
	  `parent_id` int(10) unsigned default NULL,
	  `root_id` int(10) unsigned default NULL,
	  `lft` mediumint(8) unsigned default NULL,
	  `rght` mediumint(8) unsigned default NULL,
	  `level` mediumint(8) unsigned default NULL,
	  PRIMARY KEY  (`id`),
	  KEY `rght` USING BTREE (`root_id`,`rght`,`lft`),
	  KEY `lft` USING BTREE (`root_id`,`lft`,`rght`),
	  KEY `parent_id` USING BTREE (`parent_id`,`sticky`,`created`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8

### Example 2
This following config is meant for small trees that are mainly retrieved and not often updated. It keeps track of a tree without root_id's and level caching disabled. It is ideal for e.g. Category Trees
_Note: This would also be the config for drop in's from the core Tree Behaviour_

	class Category extends AppModel {
		var $name = 'Comment';
		var $actsAs = array(
			'MultiTree' => array(
				'root' => false,
				'level' => false
				)
			);
	}

#### Schema:

	CREATE TABLE `categories` (
	  `id` int(10) unsigned NOT NULL auto_increment,
	  `name` varchar(128) NOT NULL default '',
	  `parent_id` int(10) unsigned default NULL,
	  `lft` mediumint(6) unsigned default NULL,
	  `rght` mediumint(6) unsigned default NULL,
	  PRIMARY KEY  (`id`),
	  KEY `lft` USING BTREE (`lft`),
	  KEY `parent_id` USING BTREE (`parent_id`),
	  KEY `rght` USING BTREE (`rght`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8


### Config defaults
parent: parent_id
left: lft
right: rght
root: root_id
level: level


## Traversing the tree
### Get parent
Get parent based on Parent
	debug($this->Category->getParent(32));

Get parent based on Left/Right values
	debug($this->Category->getParentFromTree(32));

### Get path
	debug($this->Category->getPath(32));

### Get level
	debug($this->Category->getLevel(32));

### Get children
	debug($this->Category->getChildren(32));

Get direct children only:
	debug($this->Category->getChildren(32, true));

### Get child count
	debug($this->Category->getChildCount(32));

### Get siblings
	debug($this->Category->getSiblings(32));
	debug($this->Category->getSiblings(32, true)); // Get siblings including the node itself

### Get previous siblings
	debug($this->Category->getPrevSiblings(32));
	debug($this->Category->getPrevSiblings(32, true)); // Get previous siblings including the node itself

### Get next siblings
	debug($this->Category->getNextSiblings(32));
	debug($this->Category->getNextSiblings(32, true)); // Get next siblings including the node itself

### Get previous sibling
	debug($this->Category->getPrevSibling(32));

### Get next sibling
	debug($this->Category->getNextSibling(32));

## Insert

Insert new node as the last child of node 1
	$format = array(
		'name' => 'Cat',
		'parent_id' => 1
		);
	$this->Category->save($format);

Insert new node as the next sibling of node 4

	$format = array(
		'name' => 'Lion',
		'parent_id' => array('destination' => 4, 'position' => 'nextSibling')
		);
	$this->Category->save($format);

Not setting a parent_id or nulling it out will insert the node as a top level (root) node

	$format = array(
		'name' => 'Animal',
		'parent_id' => null
		);
	$this->Category->save($format);


## Move

	$this->Category->move(6, 12, 'firstChild'); // Move node 6 to be the first child of node 12
	$this->Category->move(6, 12, 'lastChild'); // Move node 6 to be the last child of node 12
	$this->Category->move(6, 12, 'prevSibling'); // You get the idea..
	$this->Category->move(6, 12, 'nextSibling');

Move node 9 up by 2 (if possible, otherwise move as high up as possible)

	$this->Category->moveUp(9, 2);

Move node 9 down by 3 (if possible, otherwise move as low down as possible)

	$this->Category->moveDown(9, 3);

Will make node 6 a new top level (root) node

	$this->Category->move(6, null);

## Delete
	$this->Category->delete(25); // Same as removeFromTree(25)

This will delete node 25 and all its children

	$this->Category->removeFromTree(25);

This will delete node 25 itself but if it has any children shift them one level up

	$this->Category->removeFromTree(25, false);

## Repair
left and right values are broken but we have valid parent_id's

	$this->Category->repair('tree');

parent_id's are broken but we have valid left and right values

	$this->Category->repair('parent');

[1]: http://bakery.cakephp.org/articles/view/multitree-behavior
[2]: http://book.cakephp.org/view/228/Basic-Usage