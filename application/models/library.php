<?
class Library extends CI_Model {
	function Get($libraryid) {
		$this->db->from('libraries');
		$this->db->where('libraryid', $libraryid);
		$this->db->limit(1);
		return $this->db->get()->row_array();
	}

	var $basketCache;
	/**
	* Retrieves the temporary search basket
	* This function uses caching
	* @param boolean $create If the basket does not exist it will be created
	* @return array The data for the search basket
	*/
	function GetBasket($create = FALSE) {
		if ($this->basketCache)
			return $this->basketCache;

		$this->db->from('libraries');
		$this->db->where('title', BASKET_NAME);
		$this->db->where('status', 'active');
		$this->db->join('user2library', 'user2library.libraryid = libraries.libraryid');
		$this->db->where('user2library.userid', $this->User->GetActive('userid'));
		$this->db->limit(1);
		if ($result = $this->db->get()->row_array())
			return $result;
		if ($create) {
			$bid = $this->Create(array(
				'title' => BASKET_NAME
			));
			$this->basketCache = $this->Get($bid);
			return $this->basketCache;
		}
		return FALSE;
	}

	function GetAll($where = null, $orderby = 'title') {
		$this->db->select('libraries.*');
		$this->db->from('libraries');

		if (isset($where['userid'])) {
			$this->db->join('user2library', 'user2library.libraryid = libraries.libraryid AND user2library.userid = ' . $where['userid']);
			unset($where['userid']);
		}

		if ($where)
			$this->db->where($where);
		if ($orderby)
			$this->db->order_by($orderby);
		return $this->db->get()->result_array();
	}

	function GetAllTags($libraryid) {
		$this->db->from('referencetags');
		$this->db->where('libraryid', $libraryid);
		return $this->db->get()->result_array();
	}

	function DeleteTag($libraryid, $tagid) {
		// Remove tag from references that point to it
		$this->db->where('libraryid', $libraryid);
		$this->db->where('referencetagid', $tagid);
		$this->db->update('references', array(
			'referencetagid' => null,
		));

		// Delete the tag record
		$this->db->where('libraryid', $libraryid);
		$this->db->where('referencetagid', $tagid);
		$this->db->delete('referencetags');
	}

	/**
	* Attempt to add a tag to a reference library
	* @param int $libraryid The library to add the tag to
	* @param string $name The name of the tag to create
	* @return int Eiher the created or existing tag id
	*/
	function AddTag($libraryid, $name) {
		$this->db->from('referencetags');
		$this->db->where('libraryid', $libraryid);
		$this->db->where('title', $name);
		$row = $this->db->get()->row_array();
		if ($row) // Already exists
			return $row['referencetagid'];

		$this->db->insert('referencetags', array(
			'libraryid' => $libraryid,
			'title' => $name,
		));
		return $this->db->insert_id();
	}

	/**
	* Returns true if the given library has the reference matching references.yourref
	* @param string $yourref The string to match against references.yourref
	* @param int $libaryid OPTIONAL library to search. If none is specifed all user owned librarys are searched
	* @return boolean True if the reference matching references.yourref exists within the given library
	*/
	function Has($yourref, $libraryid = null) {
		$this->db->select('references.*');
		$this->db->from('references');
		$this->db->where('yourref', $yourref);
		if ($libraryid) {
			$this->db->where('libraryid', $libraryid);
			$this->db->where('status', 'active');
		} else { // Search all user owned libraries
			$this->db->join('libraries', 'libraries.libraryid = references.libraryid');
			$this->db->join('user2library', 'user2library.libraryid = libraries.libraryid');
			$this->db->where('user2library.userid', $this->User->GetActive('userid'));
			$this->db->where('references.status', 'active');
		}
		return $this->db->get()->row_array();
	}

	function Count($where = null) {
		$this->db->select('COUNT(*) AS count');
		$this->db->from('libraries');
		if ($where)
			$this->db->where($where);
		$row = $this->db->get()->row_array();
		return $row['count'];
	}

	function Create($data) {
		$fields = array();
		foreach (qw('title debug') as $field)
			if (isset($data[$field]))
				$fields[$field] = $data[$field];

		if ($fields) {
			$fields['created'] = time();
			$this->db->insert('libraries', $fields);
			$lid = $this->db->insert_id();
			$this->AddUser($this->User->GetActive('userid'), $lid);
			return $lid;
		}
	}

	function AddUser($userid, $libraryid) {
		// Already linked?
		$this->db->from('user2library');
		$this->db->where('userid', $userid);
		$this->db->where('libraryid', $libraryid);
		if ($this->db->get()->result_array())
			return;

		$this->db->insert('user2library', array(
			'userid' => $userid,
			'libraryid' => $libraryid,
			'created' => time(),
		));
	}

	function RemoveUser($userid, $libraryid) {
		$this->db->where('userid', $userid);
		$this->db->where('libraryid', $libraryid);
		$this->db->delete('user2library');
	}

	function Clear($libraryid) {
		$this->db->where('libraryid', $libraryid);
		$this->db->update('references', array(
			'status' => 'deleted',
		));
	}

	/**
	* Checks that the current user has permissions to edit this reference library
	* @param object $library The library object to check
	* @return bool Boolean as to whether the current user can edit this library
	*/
	function CanEdit($library) {
		if ($this->User->IsAdmin()) // Admin/root can edit everything
			return true;
		if ($library['status'] == 'deleted') // No if deleted
			return false;
		// Check if owner {{{
		$this->db->from('user2library');
		$this->db->where('libraryid', $library['libraryid']);
		$this->db->where('userid', $this->User->GetActive('userid'));
		if ($this->db->get()->row_array())
			return true;
		// }}}
		return false;
	}

	function Save($libraryid, $data) {
		$fields = array();
		foreach (qw('title debug') as $field)
			if (isset($data[$field]))
				$fields[$field] = $data[$field];

		if ($fields) {
			$fields['edited'] = time();
			$this->db->where('libraryid', $libraryid);
			$this->db->update('libraries', $fields);
			return $this->db->insert_id();
		}
	}

	function SaveDupeStatus($libraryid, $ref1, $ref2) {
		$this->db->where('libraryid', $libraryid);
		$this->db->update('libraries', array(
			'dedupe_refid' => $ref1,
			'dedupe_refid2' => $ref2,
		));
	}

	function ResetDupeStatus($libraryid) {
		$this->Library->SaveDupeStatus($libraryid, 0, 0);
		$this->Library->SetStatus($libraryid, 'dedupe');

		// Reset child references
		$this->db->where('libraryid', $libraryid);
		$this->db->where('status', 'dupe');
		$this->db->update('references', array(
			'status' => 'active', // Restore deleted
			'altdata' => '', // Wipe alternative data
		));
	}

	/**
	* Set the libraries.status value of a specific library
	* @param int $libraryid The libraryID to change
	*/
	function SetStatus($libraryid, $status) {
		$this->db->where('libraryid', $libraryid);
		$this->db->update('libraries', array(
			'status' => $status,
		));
	}

	/**
	* Returns a bool indicating whether the given tag exists for the given library
	* @param int $libraryid The library to query
	* @param int $title The tag to query
	* @returns bool Whether the tag exists
	*/
	function HasTag($libraryid, $title) {
		$this->db->where('libraryid', $libraryid);
		$this->db->where('title', $title);
		$this->db->from('referencetags');
		return (bool) $this->db->get()->row_array();
	}

	/**
	* Create a new tag for the given library
	* NOTE: This function checks HasTag() first to verify that the tag does not exist
	* @param int $libraryid The library on which to create the tag
	* @param array $data The data of the tag to create
	*/
	function CreateTag($libraryid, $data) {
		if (!$this->HasTag($libraryid, $data['title'])) {
			$this->db->insert('referencetags', array(
				'libraryid' => $libraryid,
				'title' => $data['title'],
			));
		}
	}
}
