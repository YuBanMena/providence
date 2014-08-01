<?php
/** ---------------------------------------------------------------------
 * app/lib/core/Datamodel.php : 
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2005-2014 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of 
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
 *
 * This source code is free and modifiable under the terms of 
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * @package CollectiveAccess
 * @subpackage Core
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 *
 * ----------------------------------------------------------------------
 */
 
 /**
  *
  */

require_once(__CA_LIB_DIR__."/core/Error.php");
require_once(__CA_LIB_DIR__."/core/Configuration.php");
require_once(__CA_LIB_DIR__."/core/Utils/Graph.php");


class Datamodel {
	# --------------------------------------------------------------------------------------------
	# --- Properties
	# --------------------------------------------------------------------------------------------
	private $opo_graph;
	static $s_get_path_cache = array();
	static $s_graph = null;
	static $s_many_many_cache = array();
	
	static $s_datamodel_field_num_cache = array();
	static $s_datamodel_field_name_cache = array();
	static $s_datamodel_instance_cache = null;
	static $s_datamodel_model_instance_cache = null;
	
	static $s_datamodel_many_to_one_rel_cache = array();
	static $s_datamodel_one_to_many_rel_cache = array();
	
	static $s_datamodel_model_table_name_cache = null;
	static $s_datamodel_model_table_num_cache = null;
	# --------------------------------------------------------------------------------------------
	/**
	 *
	 */
	static public function load() {
		if (!DataModel::$s_datamodel_instance_cache) {
			DataModel::$s_datamodel_instance_cache = new Datamodel();
		}
		return DataModel::$s_datamodel_instance_cache;
	}
	# --------------------------------------------------------------------------------------------
	# --- Constructor
	# --------------------------------------------------------------------------------------------
	/**
	 *
	 */
	public function __construct($pb_dont_cache=false) {
		global $_DATAMODEL_CACHE;
			
		// is the graph already in memory?
		if (!$pb_dont_cache && DataModel::$s_graph) { return; }
		
		// is there an on-disk cache of the internal graph?
		if (is_object($vo_cache = $this->_getCacheObject())) {
			if ($va_graph = $vo_cache->load('ca_datamodel_graph')) {
				$this->opo_graph = new Graph($va_graph);
				return;
			}
		} 
		
		$o_config = Configuration::load();
 			
		if ($vs_data_model_path = $o_config->get("data_model")) {
			// is it cached in memory?
			if ($_DATAMODEL_CACHE[$vs_data_model_path]) {
				$this->opo_graph = $_DATAMODEL_CACHE[$vs_data_model_path];
				return true;
			}
			
			$o_datamodel = Configuration::load($vs_data_model_path);
			$this->opo_graph = new Graph();
			
			# add tables
			if (!$va_tables = $o_datamodel->getAssoc("tables")) { $va_tables = array(); }
			foreach($va_tables as $vs_table => $vn_num) {
				$this->opo_graph->addNode($vs_table);
				$this->opo_graph->addAttribute("num", $vn_num, $vs_table);
				$this->opo_graph->addNode("t#".$vn_num);
				$this->opo_graph->addAttribute("name", $vs_table, "t#".$vn_num);
			}
			
			# add relationships
			if (!$va_relationships = $o_datamodel->getList("relationships")) { $va_relationships = array(); }

			foreach($va_relationships as $vs_relationship) {
				$va_keys = preg_split("/[\t ]*=[\t ]*/", $vs_relationship);
				
				$vn_num_keys = sizeof($va_keys);
				
				switch($vn_num_keys) {
					case 2:					
						$vs_key1 = $va_keys[0];
						$va_tmp = preg_split('/[ ]+/', $va_keys[1]);
						$vs_key2 = $va_tmp[0];
						
						list($vs_table1, $vs_field1) = explode(".", $vs_key1);
						list($vs_table2, $vs_field2) = explode(".", $vs_key2);
						
						$vn_weight = (isset($va_tmp[1]) && (intval($va_tmp[1]) > 0)) ? intval($va_tmp[1]) : 10;
						break;
					default:
						die("Fatal error: syntax error in datamodel relationship specification: '$vs_relationship'\n");
						break;
				}
				
				
				if (!$this->opo_graph->hasNode($vs_table1)) { 
					die("Fatal error: invalid table '$vs_table1' in relationship in datamodel definition\n");
				}
				if (!$this->opo_graph->hasNode($vs_table2)) { 
					die("Fatal error: invalid table '$vs_table2' in relationship in datamodel definition\n");
				}
				
				if (!($va_attr = $this->opo_graph->getAttributes($vs_table1, $vs_table2))) {
					$va_attr = array();
					$this->opo_graph->addRelationship($vs_table1, $vs_table2);
				}
				$va_attr["relationships"][$vs_table1][$vs_table2][] = array($vs_field1, $vs_field2);
				$va_attr["relationships"][$vs_table2][$vs_table1][] = array($vs_field2, $vs_field1);
				$va_attr['WEIGHT'] = $vn_weight;
				$this->opo_graph->setAttributes($va_attr, $vs_table1, $vs_table2);
			}
			
			if (is_object($vo_cache)) {
				$vo_cache->save(DataModel::$s_graph = $this->opo_graph->getInternalData(), 'ca_datamodel_graph', array('ca_datamodel_cache'));
			}
		}
	}
	# --------------------------------------------------------------------------------------------
	# 
	# --------------------------------------------------------------------------------------------
	/**
	 *
	 */
	public function getTableNum($ps_table) {
		if (is_numeric($ps_table) ) { return $ps_table; }
		if (isset(Datamodel::$s_datamodel_model_table_num_cache[$ps_table])) { return Datamodel::$s_datamodel_model_table_num_cache[$ps_table]; }
		
		if ($this->opo_graph->hasNode($ps_table)) {
			return Datamodel::$s_datamodel_model_table_num_cache[$ps_table] = $this->opo_graph->getAttribute("num", $ps_table);
		} else {
			return Datamodel::$s_datamodel_model_table_num_cache[$ps_table] = null;
		}
	}
	# --------------------------------------------------------------------------------------------
	/**
	 *
	 */
	public function getTableName($pn_tablenum) {
		if (isset(Datamodel::$s_datamodel_model_table_name_cache[$pn_tablenum])) { return Datamodel::$s_datamodel_model_table_name_cache[$pn_tablenum]; }
		if (!is_numeric($pn_tablenum) ) { return $pn_tablenum; }
		$pn_tablenum = intval($pn_tablenum);
		if ($this->opo_graph->hasNode("t#".$pn_tablenum)) {
			return Datamodel::$s_datamodel_model_table_name_cache[$pn_tablenum] = $this->opo_graph->getAttribute("name", "t#".$pn_tablenum);
		} else {
			return Datamodel::$s_datamodel_model_table_name_cache[$pn_tablenum] = null;
		}
	}
	# --------------------------------------------------------------------------------------------
	/**
	 *
	 */
	public function getTableNames() {
		$va_table_names = array();
		foreach($this->opo_graph->getNodes() as $vs_key => $va_value) {
			if (isset($va_value["num"])) {
				$va_table_names[] = $vs_key;
			}
		}
		return $va_table_names;
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Get field number for specified field name
	 *
	 * @param string $ps_table The table name
	 * @param string $ps_field The field name
	 *
	 * @return int The field number or null if the table or field are invalid
	 */
	public function getFieldNum($ps_table, $ps_field) {
		if (isset(DataModel::$s_datamodel_field_num_cache[$ps_table.'/'.$ps_field])) { return DataModel::$s_datamodel_field_num_cache[$ps_table.'/'.$ps_field]; }
		if ($t_table = $this->getInstanceByTableName($ps_table, true)) {
			$va_fields = $t_table->getFieldsArray();
			return DataModel::$s_datamodel_field_num_cache[$ps_table.'/'.$ps_field] = array_search($ps_field, array_keys($va_fields));
		} else {
			return null;
		}
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Get field name for specified field number
	 *
	 * @param string $ps_table The table name
	 * @param int $pn_field_num The field number
	 *
	 * @return int The field name or null if the table or field number are invalid
	 */
	public function getFieldName($ps_table, $pn_field_num) {
		if (isset(DataModel::$s_datamodel_field_name_cache[$ps_table.'/'.$pn_field_num])) { return DataModel::$s_datamodel_field_name_cache[$ps_table.'/'.$pn_field_num]; }
		if ($t_table = $this->getInstanceByTableName($ps_table, true)) {
			$va_fields = $t_table->getFieldsArray();
			$va_field_list = array_keys($va_fields);
			return DataModel::$s_datamodel_field_name_cache[$ps_table.'/'.$pn_field_num] = $va_field_list[(int)$pn_field_num];
		} else {
			return null;
		}
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Get information for field from model 
	 *
	 * @param string $ps_table The table name
	 * @param int $ps_field_name The field name
	 * @param string $ps_key A model info key
	 *
	 * @return mixed If $ps_key is set the specified value will be returned, which may be a string, number or array. If $ps_key is omitted the entire information array is returned.
	 */
	public function getFieldInfo($ps_table, $ps_field, $ps_key=null) {
		if ($t_table = $this->getInstanceByTableName($ps_table, true)) {
			$va_info = $t_table->getFieldInfo($ps_field);
			if ($ps_key) { return $va_info[$ps_key]; }
			return $va_info;
		} else {
			return null;
		}
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Check if table exists in datamodel
	 * 
	 * @param string $ps_table The name of the table to check for
	 * @return bool True if it exists, false if it doesn't
	 */
	public function tableExists($ps_table) {
		if ($this->opo_graph->hasNode($ps_table)) {
			return true;
		} else {
			return false;
		}
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Return model instance for specified table. Unlike the older Datamodel::getInstanceByTableName() and 
	 * Datamodel::getInstanceByTableNum(), getInstance() can take either a table name or number.
	 *
	 * By default a cached instance is returned. The initial state (Eg. is a row loaded, field values) for the returned cached instance is undefined
	 * and may reflect previous use and may be referenced by previous callers. You should be sure to do any initialization required before use, 
	 * or don't use the cache. When caching is bypassed you are guaranteed a newly created, freshly initialized instance.
	 *
	 * @param mixed $pm_table_name_or_num
	 * @param bool $pb_use_cache Use a cached instance. [Default is false]
	 */
	public function getInstance($pm_table_name_or_num, $pb_use_cache=false) {
		if (is_numeric($pm_table_name_or_num)) {
			return $this->getInstanceByTableNum($pm_table_name_or_num, $pb_use_cache);
		}
		return $this->getInstanceByTableName($pm_table_name_or_num, $pb_use_cache);
	}
	# --------------------------------------------------------------------------------------------
	/**
	 *
	 */
	# Returns an object representing table; object can be used to manipulate records or get information
	# on various table attributes.
	public function getInstanceByTableName($ps_table, $pb_use_cache=false) {
		if ($pb_use_cache && isset(DataModel::$s_datamodel_model_instance_cache[$ps_table]) && DataModel::$s_datamodel_model_instance_cache[$ps_table]) { 
			return DataModel::$s_datamodel_model_instance_cache[$ps_table];
		}
		
		if ($this->opo_graph->hasNode($ps_table)) {
			if (!isset(DataModel::$s_datamodel_model_instance_cache[$ps_table]) || !DataModel::$s_datamodel_model_instance_cache[$ps_table]) { 
				if (!file_exists(__CA_MODELS_DIR__.'/'.$ps_table.'.php')) { return null; }
				require_once(__CA_MODELS_DIR__.'/'.$ps_table.'.php'); # class file name has trailing '.php'
			}
			return DataModel::$s_datamodel_model_instance_cache[$ps_table] = new $ps_table;
		} else {
			return null;
		}
	}
	# --------------------------------------------------------------------------------------------
	/**
	 *
	 */
	# Returns an object representing table; object can be used to manipulate records or get information
	# on various table attributes.
	public function getInstanceByTableNum($pn_tablenum, $pb_use_cache=false) {
		if ($vs_class_name = $this->getTableName($pn_tablenum)) {
			if ($pb_use_cache && isset(DataModel::$s_datamodel_model_instance_cache[$vs_class_name]) && DataModel::$s_datamodel_model_instance_cache[$vs_class_name]) { 
				return DataModel::$s_datamodel_model_instance_cache[$vs_class_name];
			}
			
			if (!isset(DataModel::$s_datamodel_model_instance_cache[$vs_class_name]) || !DataModel::$s_datamodel_model_instance_cache[$vs_class_name]) { 
				if (!file_exists(__CA_MODELS_DIR__.'/'.$vs_class_name.'.php')) { return null; }
				require_once(__CA_MODELS_DIR__.'/'.$vs_class_name.'.php'); # class file name has trailing '.php'
			}
			return DataModel::$s_datamodel_model_instance_cache[$vs_class_name] = new $vs_class_name;
		} else {
			return null;
		}
	}
	# --------------------------------------------------------------------------------------------
	/**
	 *
	 */
	# Alias for $this->getInstanceByTableName()
	public function getTableInstance($ps_table, $pb_use_cache=false) {
		return $this->getInstanceByTableName($ps_table, $pb_use_cache);
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Returns field name of primary key for table
	 *
	 * @param mixed $pn_tablenum An integer table number or string table name
	 * @return string The name of the primary key
	 */
	public function getTablePrimaryKeyName($pn_tablenum) {
		if ($t_instance = is_numeric($pn_tablenum) ? $this->getInstanceByTableNum($pn_tablenum, true) : $this->getInstanceByTableName($pn_tablenum, true)) {
			return $t_instance->primaryKey();
		}
		return null;
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Returns property $ps_property from table with number equal to $pn_tablenum
	 *
	 * @param int $pn_tablenum Table number
	 * @param string $ps_property Name of model properly (eg. "NAME_PLURAL")
	 * @return string Value of property or null if $pn_tablenum is invalid
	 */
	public function getTableProperty($pn_tablenum, $ps_property) {
		if ($t_instance = $this->getInstanceByTableNum($pn_tablenum, true)) {
			return $t_instance->getProperty($ps_property);
		}
		return null;
	}
	# --------------------------------------------------------------------------------------------
	# 
	# --------------------------------------------------------------------------------------------
	/**
	 * Returns a list of relations where the specified table is the "many" end. In other words, get
	 * details for all foreign keys in the specified table 
	 */
	public function getManyToOneRelations ($ps_table, $ps_field=null) {
		if(isset(Datamodel::$s_datamodel_many_to_one_rel_cache[$ps_table.'/'.$ps_field])) {
			return Datamodel::$s_datamodel_many_to_one_rel_cache[$ps_table.'/'.$ps_field];
		}
		if ($o_table = $this->getInstanceByTableName($ps_table, true)) {
			$va_related_tables = $this->opo_graph->getNeighbors($ps_table);
			$vs_table_pk = $o_table->primaryKey();
			
			$va_many_to_one_relations = array();
			foreach($va_related_tables as $vs_related_table) {
				$va_relationships = $this->opo_graph->getAttribute("relationships", $ps_table, $vs_related_table);

				if (is_array($va_relationships[$ps_table][$vs_related_table])) {
					foreach($va_relationships[$ps_table][$vs_related_table] as $va_fields) {
						if ($va_fields[0] != $vs_table_pk) {
							if ($ps_field) {
								if ($va_fields[0] == $ps_field) {
									return Datamodel::$s_datamodel_many_to_one_rel_cache[$ps_table.'/'.$ps_field] = array(
										"one_table" 		=> $vs_related_table,
										"one_table_field" 	=> $va_fields[1],
										"many_table" 		=> $ps_table,
										"many_table_field" 	=> $va_fields[0]
									);
								}
							} else {
								$va_many_to_one_relations[$va_fields[0]] = array(
									"one_table" 			=> $vs_related_table,
									"one_table_field" 		=> $va_fields[1],
									"many_table"			=> $ps_table,
									"many_table_field" 		=> $va_fields[0]
								);
							}
						}
					}
				}
			}
			return Datamodel::$s_datamodel_many_to_one_rel_cache[$ps_table.'/'.$ps_field] = $va_many_to_one_relations;
		} else {
			return Datamodel::$s_datamodel_many_to_one_rel_cache[$ps_table.'/'.$ps_field] = null;
		}
	}
	# --------------------------------------------------------------------------------------------
	/**
	 *
	 */
	public function getOneToManyRelations ($ps_table, $ps_many_table=null) {
		if (isset(Datamodel::$s_datamodel_one_to_many_rel_cache[$ps_table.'/'.$ps_many_table])) {
			return Datamodel::$s_datamodel_one_to_many_rel_cache[$ps_table.'/'.$ps_many_table];
		}
		if ($o_table = $this->getInstanceByTableName($ps_table, true)) {
			$va_related_tables = $this->opo_graph->getNeighbors($ps_table);
			$vs_table_pk = $o_table->primaryKey();
			
			$va_one_to_many_relations = array();
			foreach($va_related_tables as $vs_related_table) {
				$va_relationships = $this->opo_graph->getAttribute("relationships", $ps_table, $vs_related_table);
				
				if (is_array($va_relationships[$ps_table][$vs_related_table])) {
					foreach($va_relationships[$ps_table][$vs_related_table] as $va_fields) {
						if ($va_fields[0] == $vs_table_pk) {
							if ($ps_many_table) {
								if ($ps_many_table == $vs_related_table) {
									return Datamodel::$s_datamodel_one_to_many_rel_cache[$ps_table.'/'.$ps_many_table] = array(
										"one_table" 		=> $ps_table,
										"one_table_field" 	=> $va_fields[0],
										"many_table" 		=> $vs_related_table,
										"many_table_field" 	=> $va_fields[1]
									);
								}
							} else {
								$va_one_to_many_relations[$vs_related_table][] = array(
									"one_table" 			=> $ps_table,
									"one_table_field" 		=> $va_fields[0],
									"many_table" 			=> $vs_related_table,
									"many_table_field" 		=> $va_fields[1]
								);
							}
						}
					}
				}
			}
			return Datamodel::$s_datamodel_one_to_many_rel_cache[$ps_table.'/'.$ps_many_table] = $va_one_to_many_relations;
		} else {
			return Datamodel::$s_datamodel_one_to_many_rel_cache[$ps_table.'/'.$ps_many_table] = null;
		}
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * returns list of many-many relations involving the specific table
	 */
	public function getManyToManyRelations ($ps_table, $ps_table2=null) {
		if(isset(Datamodel::$s_many_many_cache["{$ps_table}/{$ps_table2}"])) {
			return Datamodel::$s_many_many_cache["{$ps_table}/{$ps_table2}"];
		}
		if ($o_table = $this->getInstanceByTableName($ps_table, true)) {
			$vs_table_pk = $o_table->primaryKey();
			
			# get OneToMany relations for this table
			$va_many_many_relations = array();
			
			$va_one_to_many_relations = $this->getOneToManyRelations($ps_table);
			
			foreach($va_one_to_many_relations as $vs_left_table => $va_left_relations) {
				foreach($va_left_relations as $va_left_relation) {
					# get ManyToOne relation for this
					$va_many_to_one_relations = $this->getManyToOneRelations($va_left_relation["many_table"]);
			
					if (is_array($va_many_to_one_relations)) {
						foreach($va_many_to_one_relations as $vs_field => $va_right_relation) {
							if ($ps_table != $va_right_relation["one_table"]) {
								if ($ps_table2 == $va_right_relation["one_table"]) {
									return Datamodel::$s_many_many_cache["{$ps_table}/{$ps_table2}"] = $va_left_relation["many_table"];
								}
								$va_many_many_relations[] = array(
									"left_table" 						=> $ps_table,
									"left_table_field" 					=> $vs_table_pk,
									"linking_table" 					=> $va_left_relation["many_table"],
									"linking_table_left_field" 			=> $va_left_relation["many_table_field"],
									"linking_table_right_field" 		=> $va_right_relation["many_table_field"],
									"right_table" 						=> $va_right_relation["one_table"],
									"right_table_field" 				=> $va_right_relation["one_table_field"]
								);
							}
						}
					}
				}
			}
			
			return Datamodel::$s_many_many_cache["{$ps_table}/{$ps_table2}"] = $va_many_many_relations;
		} else {
			return Datamodel::$s_many_many_cache["{$ps_table}/{$ps_table2}"] = null;
		}
	}
	# --------------------------------------------------------------------------------------------
	/**
	 *
	 */
	public function getPath($ps_left_table, $ps_right_table) {
		if (is_numeric($ps_left_table)) { $ps_left_table = $this->getTableName($ps_left_table); }
		if (is_numeric($ps_right_table)) { $ps_right_table = $this->getTableName($ps_right_table); }
		if (isset(DataModel::$s_get_path_cache[$ps_left_table.'/'.$ps_right_table])) { return DataModel::$s_get_path_cache[$ps_left_table.'/'.$ps_right_table]; }
		
		$vo_cache = $this->_getCacheObject();
		
		if (is_object($vo_cache)) {
			if (is_array($va_cache_data = $vo_cache->load('ca_datamodel_path_'.$ps_left_table.'_'.$ps_right_table))) {
				return $va_cache_data;
			}
		}
		
		# handle self relationships as a special case
       if($ps_left_table == $ps_right_table) {
             //define rel table
             $rel_table  = $ps_left_table . "_x_" . str_replace("ca_","",$ps_left_table);
             if (!$this->getInstanceByTableName($rel_table, true)) {
             	return array();		// self relation doesn't exist
             }
             return array($ps_left_table=>$this->getTableNum($ps_left_table),$rel_table=>$this->getTableNum($rel_table));
        }
 		
 		DataModel::$s_get_path_cache[$ps_left_table.'/'.$ps_right_table] = $this->opo_graph->getPath($ps_left_table, $ps_right_table);
 		if (is_object($vo_cache)) {
 			$vo_cache->save(DataModel::$s_get_path_cache[$ps_left_table.'/'.$ps_right_table], 'ca_datamodel_path_'.$ps_left_table.'_'.$ps_right_table, array('ca_datamodel_cache'));
 		}
 		return DataModel::$s_get_path_cache[$ps_left_table.'/'.$ps_right_table];
	}
	# --------------------------------------------------------------------------------------------
	/**
	 *
	 */
	public function getRelationships($ps_left_table, $ps_right_table) {
		$va_relationships = $this->opo_graph->getAttribute("relationships", $ps_left_table, $ps_right_table);
		
		return $va_relationships;
	}
	# --------------------------------------------------------------------------------------------
	/** 
	 *
	 */
	private function _getCacheObject() {
		
		$va_frontend_options = array(
			'lifetime' => null, 				/* cache lives forever (until manual destruction) */
			'logging' => false,					/* do not use Zend_Log to log what happens */
			'write_control' => true,			/* immediate read after write is enabled (we don't write often) */
			'automatic_cleaning_factor' => 0, 	/* no automatic cache cleaning */
			'automatic_serialization' => true	/* we store arrays, so we have to enable that */
		);
		$vs_cache_dir = __CA_APP_DIR__.'/tmp';
		$va_backend_options = array(
			'cache_dir' => $vs_cache_dir,		/* where to store cache data? */
			'file_locking' => true,				/* cache corruption avoidance */
			'read_control' => false,			/* no read control */
			'file_name_prefix' => 'ca_datamodel',	/* prefix of datamodel cache files */
			'cache_file_perm' => 0700			/* permissions of cache files */
		);

		try {
			$vo_cache = Zend_Cache::factory('Core', 'File', $va_frontend_options, $va_backend_options);
		} catch (Exception $e) {
			// ok... just keep on going
			$vo_cache = null;
		}
		
		return $vo_cache;
	}
	# --------------------------------------------------------------------------------------------
	public function __destruct() {
		//print "DESTRUCT datamodel\n";
	}
	# --------------------------------------------------------------------------------------------
}