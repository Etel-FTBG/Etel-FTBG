<?php
/** An error that is safe to show to the person using the app (status + message). */
class ApiError extends Exception
{
    public $status;

    public function __construct($status, $message)
    {
        parent::__construct($message);
        $this->status = (int) $status;
    }
}
