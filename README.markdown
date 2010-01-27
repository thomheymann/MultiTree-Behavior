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
<pre><code>class Comment extends AppModel {
	var $name = 'Comment';
	var $actsAs = array(
		'MultiTree' => array(
			'root' =>'root_id',
			'level' =>'level'
			)
		);
}</pre></code>
#### Schema
<pre><code>CREATE TABLE `comments` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8</pre></code>

### Example 2
This following config is meant for small trees that are mainly retrieved and not often updated. It keeps track of a tree without root_id's and level caching disabled. It is ideal for e.g. Category Trees
[i]Note: This would also be the config for drop in's from the core Tree Behaviour[/i]
<pre><code>class Category extends AppModel {
	var $name = 'Comment';
	var $actsAs = array(
		'MultiTree' => array(
			'root' => false,
			'level' => false
			)
		);
}</pre></code>
#### Schema
<pre><code>CREATE TABLE `categories` (
  `id` int(10) unsigned NOT NULL auto_increment,
  `name` varchar(128) NOT NULL default '',
  `parent_id` int(10) unsigned default NULL,
  `lft` mediumint(6) unsigned default NULL,
  `rght` mediumint(6) unsigned default NULL,
  PRIMARY KEY  (`id`),
  KEY `lft` USING BTREE (`lft`),
  KEY `parent_id` USING BTREE (`parent_id`),
  KEY `rght` USING BTREE (`rght`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8</pre></code>


### Config defaults
parent: parent_id
left: lft
right: rght
root: root_id
level: level


## Traversing the tree
### Get parent
Get parent based on Parent
<pre><code>debug($this->Category->getParent(32));</pre></code>

Get parent based on Left/Right values
<pre><code>debug($this->Category->getParentFromTree(32));</pre></code>

### Get path
<pre><code>debug($this->Category->getPath(32));</pre></code>

### Get level
<pre><code>debug($this->Category->getLevel(32));</pre></code>

### Get children
<pre><code>debug($this->Category->getChildren(32));</pre></code>

Get direct children only:
<pre><code>debug($this->Category->getChildren(32, true));</pre></code>

### Get child count
<pre><code>debug($this->Category->getChildCount(32));</pre></code>

### Get siblings
<pre><code>debug($this->Category->getSiblings(32));</pre></code>

Get siblings including the node itself
<pre><code>debug($this->Category->getSiblings(32, true));</pre></code>

### Get previous siblings
<pre><code>debug($this->Category->getPrevSiblings(32));</pre></code>

Get previous siblings including the node itself
<pre><code>debug($this->Category->getPrevSiblings(32, true));</pre></code>

### Get next siblings
<pre><code>debug($this->Category->getNextSiblings(32));</pre></code>

Get next siblings including the node itself
<pre><code>debug($this->Category->getNextSiblings(32, true));</pre></code>

### Get previous sibling
<pre><code>debug($this->Category->getPrevSibling(32));</pre></code>

### Get next sibling
<pre><code>debug($this->Category->getNextSibling(32));</pre></code>


## Insert
Insert new node as the last child of node 1
<pre><code>$format = array(
	'name' => 'Cat',
	'parent_id' => 1
	);
$this->Category->save($format);</pre></code>

Insert new node as the next sibling of node 4
<pre><code>$format = array(
	'name' => 'Lion',
	'parent_id' => array('destination' => 4, 'position' => 'nextSibling')
	);
$this->Category->save($format);</pre></code>

Not setting a parent_id or nulling it out will insert the node as a top level (root) node
<pre><code>$format = array(
	'name' => 'Animal',
	'parent_id' => null
	);
$this->Category->save($format);</pre></code>


## Move
<pre><code>$this->Category->move(6, 12, 'firstChild'); // Move node 6 to be the first child of node 12
$this->Category->move(6, 12, 'lastChild'); // Move node 6 to be the last child of node 12
$this->Category->move(6, 12, 'prevSibling'); // You get the idea..
$this->Category->move(6, 12, 'nextSibling');</pre></code>

Move node 9 up by 2 (if possible, otherwise move as high up as possible)
<pre><code>$this->Category->moveUp(9, 2);</pre></code>

Move node 9 down by 3 (if possible, otherwise move as low down as possible)
<pre><code>$this->Category->moveDown(9, 3);</pre></code>

Will make node 6 a new top level (root) node
<pre><code>$this->Category->move(6, null);</pre></code>


## Delete
<pre><code>$this->Category->delete(25); // Same as removeFromTree(25)</pre></code>

This will delete node 25 and all its children
<pre><code>$this->Category->removeFromTree(25);</pre></code>

This will delete node 25 itself but if it has any children shift them one level up
<pre><code>$this->Category->removeFromTree(25, false);</pre></code>


## Repair
left and right values are broken but we have valid parent_id's
<pre><code>$this->Category->repair('tree');</pre></code>

parent_id's are broken but we have valid left and right values
<pre><code>$this->Category->repair('parent');</pre></code>

[1]: http://bakery.cakephp.org/articles/view/multitree-behavior
[2]: http://book.cakephp.org/view/228/Basic-Usage