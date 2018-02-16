<?php
/**
 *
 * @package	Codeigniter Library for Datatable Serverside JSON Generator
 * @author	Fuad Ar-Radhi
 * @link	https://github.com/arradyscode/ci-datatable-serverside
 * @since	Version 2.0.0
 *
 * @filesource
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Arc_datatable {

	/**
	 * Instance dari Codeigniter
	 * 
	 * @var instance
	 */
	private $arradyscode;

	/**
	 * Query yang akan digenerate untuk json datatable
	 * 
	 * @var string
	 */
	private $query_string;

	/**
	 * Kolom yang akan diproses, ditampilkan, digunakan pada
	 * pencarian dan ordering.
	 * 
	 * @var array of string
	 */
	private $columns;

	/**
	 * Gunakan kolom pertama sebagai baris
	 * @var boolean
	 */
	private $lines = true;

	/**
	 * Kolom virtual
	 * 
	 * @var array
	 */
	private $virtual = array();

	/**
	 * Key Value
	 * 
	 * @var array
	 */
	private $use_key = false;


	/**
	 * Init library
	 */
	public function __construct()
	{
		$this->arradyscode =& get_instance();
	}


	/**
	 * Set Query yang akan digenerate menjadi json
	 * 
	 * @param string $query
	 */
	public function set_query( $query = '')
	{
		$this->query_string = $query;
		return $this;
	}


	/**
	 * Set Kolom-kolom yang akan diproses, ditampilkan, dijadikan order dan pencarian
	 * 
	 * @param array atau string $column
	 * @param lambda $function_callback
	 */
	public function set_column( $column, $function_callback = null)
	{
		if(is_array($column))
		{
			$columns = array();
			foreach ($column as $key => $value) {
				if (is_numeric($key))
				{
					$columns[] = $value;
				}
				else
				{
					$this->set_column($key, $value);
					$columns[] = $key;
				}
			}

			$this->columns = $columns;
		}
		elseif(is_callable($function_callback))
		{
			$clean = $this->clean_column($column);
			$this->virtual[$clean['clean_column']] = $function_callback;
		}

		return $this;

	}


	/**
	 * Gunakan Key saat Output 
	 * 
	 * @return JSON
	 */
	public function set_key($use_key = false)
	{	
		$this->use_key = $use_key;

		return $this;

	}

	/**
	 * Gunakan Lines saat Output 
	 * 
	 * @return JSON
	 */
	public function set_lines($lines = false)
	{	
		$this->lines = $lines;

		return $this;

	}


	/**
	 * Fungsi Utama, Get Json 
	 * 
	 * @return JSON
	 */
	public function get_json()
	{	
		$param 	= $this->get_input();
		$column = $this->explode_columns();
		$query 	= $this->build_query( $param, $column);
		$data  	= $this->get_data($param, $column, $query);
		$total 	= $this->get_total($query);

		$this->generate_json($data, $total);

	}


	/**
	 * Extract Parameter dari Datatable
	 * 
	 * @return array of variant
	 */
	private function get_input()
	{
		$get = $this->arradyscode->input->get();

		$param = array
		(
			'search_isset' 	=> isset($get['search']),
			'search_value' 	=> isset($get['search']) ? $get['search']['value'] : '',
			'page_limit'	=> isset($get['length']) ? $get['length']:10,
			'page_offset'	=> isset($get['start']) ? $get['start']:0,
			'order_isset'	=> isset($get['order']),
			'order_index'	=> isset($get['order']) ? $get['order'][0]['column']:'',
			'order_dir'		=> isset($get['order']) ? $get['order'][0]['dir']:'',
		);

		return $param;

	}


	/**
	 * Pisah kolom-kolom menjadi 'order','search' dan 'display'
	 * 
	 * @return array of string
	 */
	private function explode_columns(){

		$column_display = $this->lines ? array('__lines__') : array();
		$column_search  = $this->lines ? array(null) : array();
		$column_order   = $this->lines ? array(null) : array();

		foreach ($this->columns as $column)
		{	
			$proses_column    = $this->clean_column($column);

			$column_display[] = $proses_column['clean_column'];
			$column_search[]  = in_array('s', $proses_column['attribute']) ? $proses_column['original_column']:null;
			$column_order[]	  = in_array('o', $proses_column['attribute']) ? $proses_column['original_column']:null;

		}

		$columns = array
		(
			'column_display' => $column_display,
			'column_search'  => $column_search,
			'column_order' 	 => $column_order,
		);

		return $columns;

	}


	/**
	 * Build query menjadi query MariaDB/MySQL
	 * 
	 * @param  array $param  parameter dari datatable
	 * @param  array $column kolom sudah dipilah
	 * @return array $return
	 */
	private function build_query( $param, $column){

		extract($param);

		$original_query = $this->query_string;

		if ($search_isset && !empty($search_value))
		{
			$query = ' ( ';
			$count = 0;
			foreach($column['column_search'] as $col)
			{
				if( ! empty($col))
				{
					$query .= $col . ' LIKE "%'.$search_value.'%" OR ';
					$count++; 
				}
			}
			$query  = rtrim($query ,'OR ');
			$query .= ' ) ';
			$query  = $count ? $query : null;
		}
		else
		{
			$query = null;
		}

		$original_query = str_replace('__where__', $query ? ' WHERE ('.$query.') ' :'' , $original_query);
		$original_query = str_replace('__and_where__', $query ? ' AND ('.$query.') ' :'' , $original_query);

		$count_query = $original_query;
		$count_query = str_replace('__order__', '', $count_query);
		$count_query = str_replace('__limit_offset__', '', $count_query);

		$column_order = @$column['column_order'][$order_index];
		$original_query = str_replace('__order__', 
			($order_isset && $column_order != null) ? ' ORDER BY '.$column_order.' '.$order_dir:'' , $original_query);
		
		$query_limit = ($page_limit == -1) ? '':' LIMIT '.$page_limit.' OFFSET '.$page_offset;
		$original_query = str_replace('__limit_offset__',$query_limit, $original_query);

		$return = array
		(
			'original_query' => $original_query,
			'count_query' => $count_query
		);

		return $return;

	}


	/**
	 * Execute query untuk mengambil data
	 * 
	 * @param  array $param  parameter dari datatable
	 * @param  array $column kolom telah dipisah
	 * @param  array $query  query
	 * @return array
	 */
	private function get_data($param, $column, $query)
	{
		$datas = $this->arradyscode->db->query($query['original_query'])->result_array();
		
		$return = array();
		$line = $param['page_offset']+1;
		foreach ($datas as $data)
		{

			$data['line'] = $line;

			$loop = array();
			foreach ($column['column_display'] as $column_display)
			{
				if ($column_display == '__lines__')
				{
					$value = ($line).'.'; 
					$column_display = 'line';
				}
				else
				{
					if(in_array($column_display, array_keys($this->virtual)))
					{
						$object = json_decode(json_encode($data), FALSE);
						$value = $this->virtual[$column_display]($object);
					}
					else
					{
						$value = $data[$column_display];
					}
				}

				if ($this->use_key)
				{
					$loop[$column_display] = $value;
				}
				else
				{
					$loop[] = $value;
				}
				
			}
			
			$line++;
			$return[] = $loop;
		}
		
		return $return;
	
	}


	/**
	 * Execute query untuk mengambil total data
	 * 
	 * @param  array $query
	 * @return integer
	 */
	private function get_total($query)
	{
		return $this->arradyscode->db->query($query['count_query'])->num_rows();
	}


	/**
	 * Generate JSON
	 * 
	 * @param  array $data 
	 * @param  int $total
	 * @return 
	 */
	private function generate_json($data, $total)
	{

		$get = $this->arradyscode->input->get();

		header('Content-Type: application/json');
		$json['draw'] = (isset($get['draw'])) ? $get['draw'] : 1;
		$json['recordsTotal'] = $total;
		$json['recordsFiltered'] = $total;
		$json['data'] = $data;

		echo json_encode($json);
	}


	/**
	 * Clean column dari attribute
	 * 
	 * @param  string $column
	 * @return array of string
	 */
	private function clean_column($column)
	{
		preg_match('/\(([^)]*)\)/', $column, $attribute);
		$original_column = str_replace(@$attribute[0], '', $column);

		$arr_attribute = explode('|', @$attribute[1]);
		$dot_position = strpos($original_column, ".");

		$column= ($dot_position !== false) ? substr($original_column,$dot_position+1):$original_column;

		if(strpos($original_column,' as ') !== false){
			$as_explode = explode(' as ', $original_column);
			$original_column = trim($as_explode[0]);
			$column = trim($as_explode[1]);
		}

		$return = array
		(
			'clean_column' => $column,
			'attribute' => $arr_attribute,
			'original_column' => $original_column,
		);

		return $return;
	}
}
