<?php

namespace Hardtail\XmlRpcClient\Console;

use DOMDocument;
use DOMNode;
use Hardtail\XmlRpcClient\Exceptions\XmlRpcFaultException;
use Hardtail\XmlRpcClient\XmlRpcClient;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

class XmlRpcCallCommand extends Command
{
    protected $signature = 'xmlrpc:call
        {method : The XML-RPC method name to invoke}
        {args?* : Arguments to pass to the method}
        {--raw : Output the raw XML instead of colorized output}
        {--endpoint= : Override the configured endpoint URL}';

    protected $description = 'Execute an XML-RPC request and display the response';

    public function handle(XmlRpcClient $client): int
    {
        $method = $this->argument('method');
        $args = $this->argument('args');

        if ($endpoint = $this->option('endpoint')) {
            $client = new XmlRpcClient($endpoint);
        }

        $this->info("Endpoint: {$client->getEndpoint()}");
        $this->info("Method:   {$method}");

        if (!empty($args)) {
            $this->info('Args:     ' . implode(', ', $args));
        }

        $this->newLine();

        try {
            $dom = $client->callRaw($method, ...$args);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        // Check for XML-RPC fault in the response DOM.
        $fault = $dom->getElementsByTagName('fault');
        if ($fault->length > 0) {
            if ($this->option('raw')) {
                $this->output->writeln($dom->saveXML(), OutputInterface::OUTPUT_RAW);
            } else {
                $this->line($this->colorizeXml($dom));
            }
            return self::FAILURE;
        }

        if ($this->option('raw')) {
            $this->output->writeln($dom->saveXML(), OutputInterface::OUTPUT_RAW);
        } else {
            $this->line($this->colorizeXml($dom));
        }

        return self::SUCCESS;
    }

    /**
     * Colorize an XML DOMDocument for terminal output.
     */
    protected function colorizeXml(DOMDocument $dom): string
    {
        $root = $dom->documentElement;

        if (!$root) {
            return $dom->saveXML();
        }

        return $this->colorizeNode($root);
    }

    /**
     * Recursively colorize a DOMNode and its children.
     */
    protected function colorizeNode(DOMNode $node, int $indent = 0, bool $inFault = false): string
    {
        $output = '';
        $pad = str_repeat('  ', $indent);

        if ($node->nodeType === XML_ELEMENT_NODE) {
            $isFault = strtolower($node->nodeName) === 'fault';
            $nowInFault = $inFault || $isFault;

            $output .= "{$pad}<comment><{$node->nodeName}></comment>\n";

            foreach ($node->childNodes as $child) {
                $output .= $this->colorizeNode($child, $indent + 1, $nowInFault);
            }

            $output .= "{$pad}<comment></{$node->nodeName}></comment>\n";
        } elseif ($node->nodeType === XML_TEXT_NODE) {
            $text = trim($node->nodeValue);

            if ($text !== '') {
                $tag = $inFault ? 'error' : $this->detectValueColor($node);
                $output .= "{$pad}<{$tag}>{$text}</{$tag}>\n";
            }
        }

        return $output;
    }

    /**
     * Detect the appropriate color tag for a text node based on its parent element.
     */
    protected function detectValueColor(DOMNode $node): string
    {
        $parent = $node->parentNode;

        if (!$parent || $parent->nodeType !== XML_ELEMENT_NODE) {
            return 'info';
        }

        return match (strtolower($parent->nodeName)) {
            'name' => 'question',
            'int', 'i4', 'double', 'boolean' => 'comment',
            default => 'info',
        };
    }
}
