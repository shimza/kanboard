<?php

namespace Model;

use SimpleValidator\Validator;
use SimpleValidator\Validators;

/**
 * Subtask model
 *
 * @package  model
 * @author   Frederic Guillot
 */
class SubTask extends Base
{
    /**
     * SQL table name
     *
     * @var string
     */
    const TABLE = 'task_has_subtasks';

    /**
     * Task "done" status
     *
     * @var integer
     */
    const STATUS_DONE = 2;

    /**
     * Task "in progress" status
     *
     * @var integer
     */
    const STATUS_INPROGRESS = 1;

    /**
     * Task "todo" status
     *
     * @var integer
     */
    const STATUS_TODO = 0;

    /**
     * Events
     *
     * @var string
     */
    const EVENT_UPDATE = 'subtask.update';
    const EVENT_CREATE = 'subtask.create';

    /**
     * Get available status
     *
     * @access public
     * @return array
     */
    public function getStatusList()
    {
        $status = array(
            self::STATUS_TODO => t('Todo'),
            self::STATUS_INPROGRESS => t('In progress'),
            self::STATUS_DONE => t('Done'),
        );

        asort($status);

        return $status;
    }

    /**
     * Get all subtasks for a given task
     *
     * @access public
     * @param  integer   $task_id    Task id
     * @return array
     */
    public function getAll($task_id)
    {
        $status = $this->getStatusList();
        $subtasks = $this->db->table(self::TABLE)
                             ->eq('task_id', $task_id)
                             ->columns(self::TABLE.'.*', User::TABLE.'.username', User::TABLE.'.name')
                             ->join(User::TABLE, 'id', 'user_id')
                             ->asc(self::TABLE.'.id')
                             ->findAll();

        foreach ($subtasks as &$subtask) {
            $subtask['status_name'] = $status[$subtask['status']];
        }

        return $subtasks;
    }

    /**
     * Get all subtasks assigned to a user
     *
     * @access public
     * @param  integer   $user_id    User id
     * @param  array     $status     List of status
     * @return array
     */
    public function getAllByUser($user_id, array $status)
    {
        $status_list = $this->getStatusList();
        $subtasks = $this->db->table(self::TABLE)
                             ->columns(
                                self::TABLE.'.*',
                                Task::TABLE.'.project_id',
                                Task::TABLE.'.color_id',
                                Project::TABLE.'.name AS project_name'
                             )
                             ->eq('user_id', $user_id)
                             ->in(self::TABLE.'.status', $status)
                             ->join(Task::TABLE, 'id', 'task_id')
                             ->join(Project::TABLE, 'id', 'project_id', Task::TABLE)
                             ->asc(Task::TABLE.'.id')
                             ->findAll();

        foreach ($subtasks as &$subtask) {
            $subtask['status_name'] = $status_list[$subtask['status']];
        }

        return $subtasks;
    }

    /**
     * Get a subtask by the id
     *
     * @access public
     * @param  integer   $subtask_id    Subtask id
     * @param  bool      $more          Fetch more data
     * @return array
     */
    public function getById($subtask_id, $more = false)
    {
        if ($more) {

            $subtask = $this->db->table(self::TABLE)
                             ->eq(self::TABLE.'.id', $subtask_id)
                             ->columns(self::TABLE.'.*', User::TABLE.'.username', User::TABLE.'.name')
                             ->join(User::TABLE, 'id', 'user_id')
                             ->findOne();

            if ($subtask) {
                $status = $this->getStatusList();
                $subtask['status_name'] = $status[$subtask['status']];
            }

            return $subtask;
        }

        return $this->db->table(self::TABLE)->eq('id', $subtask_id)->findOne();
    }

    /**
     * Prepare data before insert/update
     *
     * @access public
     * @param  array    $values    Form values
     */
    public function prepare(array &$values)
    {
        $this->removeFields($values, array('another_subtask'));
        $this->resetFields($values, array('time_estimated', 'time_spent'));
    }

    /**
     * Create
     *
     * @access public
     * @param  array    $values    Form values
     * @return bool
     */
    public function create(array $values)
    {
        $this->prepare($values);
        $result = $this->db->table(self::TABLE)->save($values);

        if ($result) {
            $values['id'] = $this->db->getConnection()->getLastId();
            $this->event->trigger(self::EVENT_CREATE, $values);
        }

        return $result;
    }

    /**
     * Update
     *
     * @access public
     * @param  array    $values    Form values
     * @return bool
     */
    public function update(array $values)
    {
        $this->prepare($values);
        $result = $this->db->table(self::TABLE)->eq('id', $values['id'])->save($values);

        if ($result) {
            $this->event->trigger(self::EVENT_UPDATE, $values);
        }

        return $result;
    }

    /**
     * Remove
     *
     * @access public
     * @param  integer   $subtask_id    Subtask id
     * @return bool
     */
    public function remove($subtask_id)
    {
        return $this->db->table(self::TABLE)->eq('id', $subtask_id)->remove();
    }

    /**
     * Duplicate all subtasks to another task
     *
     * @access public
     * @param  integer   $src_task_id    Source task id
     * @param  integer   $dst_task_id    Destination task id
     * @return bool
     */
    public function duplicate($src_task_id, $dst_task_id)
    {
        $subtasks = $this->db->table(self::TABLE)
                             ->columns('title', 'time_estimated')
                             ->eq('task_id', $src_task_id)
                             ->findAll();

        foreach ($subtasks as &$subtask) {

            $subtask['task_id'] = $dst_task_id;
            $subtask['time_spent'] = 0;

            if (! $this->db->table(self::TABLE)->save($subtask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate creation
     *
     * @access public
     * @param  array   $values           Form values
     * @return array   $valid, $errors   [0] = Success or not, [1] = List of errors
     */
    public function validateCreation(array $values)
    {
        $rules = array(
            new Validators\Required('task_id', t('The task id is required')),
            new Validators\Required('title', t('The title is required')),
        );

        $v = new Validator($values, array_merge($rules, $this->commonValidationRules()));

        return array(
            $v->execute(),
            $v->getErrors()
        );
    }

    /**
     * Validate modification
     *
     * @access public
     * @param  array   $values           Form values
     * @return array   $valid, $errors   [0] = Success or not, [1] = List of errors
     */
    public function validateModification(array $values)
    {
        $rules = array(
            new Validators\Required('id', t('The subtask id is required')),
            new Validators\Required('task_id', t('The task id is required')),
        );

        $v = new Validator($values, array_merge($rules, $this->commonValidationRules()));

        return array(
            $v->execute(),
            $v->getErrors()
        );
    }

    /**
     * Common validation rules
     *
     * @access private
     * @return array
     */
    private function commonValidationRules()
    {
        return array(
            new Validators\Integer('id', t('The subtask id must be an integer')),
            new Validators\Integer('task_id', t('The task id must be an integer')),
            new Validators\MaxLength('title', t('The maximum length is %d characters', 100), 100),
            new Validators\Integer('user_id', t('The user id must be an integer')),
            new Validators\Integer('status', t('The status must be an integer')),
            new Validators\Numeric('time_estimated', t('The time must be a numeric value')),
            new Validators\Numeric('time_spent', t('The time must be a numeric value')),
        );
    }
}
