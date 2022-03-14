<?php
namespace SpellPayment;

class DefaultLogger
{
    private $log;

    /** @param \Psr\Log\LoggerInterface $log */
    public function __construct($log)
    {
        $this->log = $log;
    }

    /** @param string $msg */
    public function log($msg)
    {
        $this->log->info($msg);
    }
}