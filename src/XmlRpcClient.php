<?php

namespace Hardtail\XmlRpcClient;

use DOMDocument;
use DOMNode;
use Hardtail\XmlRpcClient\Exceptions\XmlRpcException;
use Hardtail\XmlRpcClient\Exceptions\XmlRpcFaultException;
use Illuminate\Support\Facades\Http;

class XmlRpcClient
{
    protected string $endpoint;
    protected ?string $username;
    protected ?string $password;

    public function __construct(string $endpoint, ?string $username = null, ?string $password = null)
    {
        $this->endpoint = $endpoint;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Execute an XML-RPC method call and return the parsed response.
     */
    public function call(string $method, mixed ...$params): mixed
    {
        if (empty($this->endpoint)) {
            throw new XmlRpcException('XML-RPC endpoint is not configured.');
        }

        $requestXml = $this->buildRequestXml($method, $params);
        $responseDom = $this->sendRequest($requestXml);

        return $this->parseResponse($responseDom);
    }

    /**
     * Execute an XML-RPC method call and return the raw DOMDocument response.
     */
    public function callRaw(string $method, mixed ...$params): DOMDocument
    {
        if (empty($this->endpoint)) {
            throw new XmlRpcException('XML-RPC endpoint is not configured.');
        }

        $requestXml = $this->buildRequestXml($method, $params);

        return $this->sendRequest($requestXml);
    }

    /**
     * Build the XML-RPC request body.
     */
    protected function buildRequestXml(string $method, array $params): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        $methodCall = $doc->appendChild($doc->createElement('methodCall'));
        $methodCall->appendChild($doc->createElement('methodName', htmlspecialchars($method)));

        if (!empty($params)) {
            $paramsElement = $methodCall->appendChild($doc->createElement('params'));

            foreach ($params as $param) {
                $paramElement = $paramsElement->appendChild($doc->createElement('param'));
                $valueElement = $paramElement->appendChild($doc->createElement('value'));
                $this->encodeValue($doc, $valueElement, $param);
            }
        }

        return $doc->saveXML();
    }

    /**
     * Encode a PHP value into an XML-RPC value element.
     */
    protected function encodeValue(DOMDocument $doc, DOMNode $parent, mixed $value): void
    {
        if (is_int($value)) {
            $parent->appendChild($doc->createElement('int', (string) $value));
        } elseif (is_float($value)) {
            $parent->appendChild($doc->createElement('double', (string) $value));
        } elseif (is_bool($value)) {
            $parent->appendChild($doc->createElement('boolean', $value ? '1' : '0'));
        } elseif (is_string($value)) {
            $parent->appendChild($doc->createElement('string', htmlspecialchars($value)));
        } elseif (is_array($value)) {
            if ($this->isAssociative($value)) {
                $this->encodeStruct($doc, $parent, $value);
            } else {
                $this->encodeArray($doc, $parent, $value);
            }
        } elseif (is_null($value)) {
            $parent->appendChild($doc->createElement('nil'));
        }
    }

    /**
     * Encode an associative array as an XML-RPC struct.
     */
    protected function encodeStruct(DOMDocument $doc, DOMNode $parent, array $value): void
    {
        $struct = $parent->appendChild($doc->createElement('struct'));

        foreach ($value as $key => $val) {
            $member = $struct->appendChild($doc->createElement('member'));
            $member->appendChild($doc->createElement('name', htmlspecialchars((string) $key)));
            $memberValue = $member->appendChild($doc->createElement('value'));
            $this->encodeValue($doc, $memberValue, $val);
        }
    }

    /**
     * Encode a sequential array as an XML-RPC array.
     */
    protected function encodeArray(DOMDocument $doc, DOMNode $parent, array $value): void
    {
        $array = $parent->appendChild($doc->createElement('array'));
        $data = $array->appendChild($doc->createElement('data'));

        foreach ($value as $val) {
            $valueElement = $data->appendChild($doc->createElement('value'));
            $this->encodeValue($doc, $valueElement, $val);
        }
    }

    /**
     * Send the XML-RPC request and return the response as a DOMDocument.
     */
    protected function sendRequest(string $xml): DOMDocument
    {
        $request = Http::withHeaders(['Content-Type' => 'text/xml']);

        if ($this->username && $this->password) {
            $request = $request->withBasicAuth($this->username, $this->password);
        }

        $response = $request->withBody($xml, 'text/xml')->post($this->endpoint);

        if ($response->failed()) {
            throw new XmlRpcException(
                "HTTP request failed with status {$response->status()}: {$response->body()}"
            );
        }

        $doc = new DOMDocument('1.0');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        $loaded = @$doc->loadXML($response->body());

        if (!$loaded) {
            throw new XmlRpcException('Failed to parse XML-RPC response: ' . $response->body());
        }

        return $doc;
    }

    /**
     * Parse an XML-RPC response DOMDocument into a PHP value.
     */
    protected function parseResponse(DOMDocument $doc): mixed
    {
        $fault = $doc->getElementsByTagName('fault');

        if ($fault->length > 0) {
            $faultValue = $this->parseValue($fault->item(0)->getElementsByTagName('value')->item(0));
            throw new XmlRpcFaultException(
                $faultValue['faultString'] ?? 'Unknown XML-RPC fault',
                $faultValue['faultCode'] ?? 0
            );
        }

        $params = $doc->getElementsByTagName('param');

        if ($params->length === 0) {
            return null;
        }

        if ($params->length === 1) {
            return $this->parseValue($params->item(0)->getElementsByTagName('value')->item(0));
        }

        $results = [];
        foreach ($params as $param) {
            $results[] = $this->parseValue($param->getElementsByTagName('value')->item(0));
        }

        return $results;
    }

    /**
     * Parse an XML-RPC <value> element into a PHP value.
     */
    protected function parseValue(DOMNode $valueNode): mixed
    {
        foreach ($valueNode->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            return match ($child->nodeName) {
                'string' => $child->textContent,
                'int', 'i4' => (int) $child->textContent,
                'double' => (float) $child->textContent,
                'boolean' => $child->textContent === '1',
                'array' => $this->parseArray($child),
                'struct' => $this->parseStruct($child),
                'nil' => null,
                'base64' => base64_decode($child->textContent),
                'dateTime.iso8601' => $child->textContent,
                default => $child->textContent,
            };
        }

        // No type element — treat text content as string per XML-RPC spec.
        return $valueNode->textContent;
    }

    /**
     * Parse an XML-RPC <array> element.
     */
    protected function parseArray(DOMNode $arrayNode): array
    {
        $result = [];
        $data = $arrayNode->getElementsByTagName('data')->item(0);

        if ($data) {
            foreach ($data->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE && $child->nodeName === 'value') {
                    $result[] = $this->parseValue($child);
                }
            }
        }

        return $result;
    }

    /**
     * Parse an XML-RPC <struct> element.
     */
    protected function parseStruct(DOMNode $structNode): array
    {
        $result = [];

        foreach ($structNode->childNodes as $member) {
            if ($member->nodeType !== XML_ELEMENT_NODE || $member->nodeName !== 'member') {
                continue;
            }

            $name = null;
            $value = null;

            foreach ($member->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }
                if ($child->nodeName === 'name') {
                    $name = $child->textContent;
                } elseif ($child->nodeName === 'value') {
                    $value = $this->parseValue($child);
                }
            }

            if ($name !== null) {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * Check if an array is associative.
     */
    protected function isAssociative(array $array): bool
    {
        if (empty($array)) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }
}
