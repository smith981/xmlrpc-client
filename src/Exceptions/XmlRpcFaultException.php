<?php

namespace Hardtail\XmlRpcClient\Exceptions;

class XmlRpcFaultException extends XmlRpcException
{
    protected int $faultCode;
    protected string $faultString;

    public function __construct(string $faultString, int $faultCode = 0)
    {
        $this->faultString = $faultString;
        $this->faultCode = $faultCode;

        parent::__construct("XML-RPC Fault [{$faultCode}]: {$faultString}", $faultCode);
    }

    public function getFaultCode(): int
    {
        return $this->faultCode;
    }

    public function getFaultString(): string
    {
        return $this->faultString;
    }
}
