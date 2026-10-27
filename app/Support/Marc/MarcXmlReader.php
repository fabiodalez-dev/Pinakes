<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * MARC records from XML, streamed with XMLReader one record at a time, so a
 * collection of any size is read in flat memory. Namespace-agnostic: MARCXML
 * (MARC 21 slim), MARCXchange (ISO 25577: UNIMARC, danMARC2) and bare
 * records, alone, in a <collection>, or wrapped in an SRU or OAI-PMH
 * response. A `record` element counts as a MARC record when it has a leader,
 * controlfield or datafield child, so the SRU/OAI envelope around it is
 * walked through, not mistaken for one.
 *
 * A record the converter cannot read is yielded as a MarcReadError in its
 * place. XML that stops being well-formed ends the file with one error.
 *
 * @implements \IteratorAggregate<int, MarcRecord|MarcReadError>
 */
final class MarcXmlReader implements \IteratorAggregate
{
    private const MARC_CHILDREN = ['leader', 'controlfield', 'datafield'];

    private function __construct(private \XMLReader $reader)
    {
    }

    public static function fromPath(string $path): self
    {
        $reader = new \XMLReader();
        if (!@$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new \RuntimeException('Cannot open MARCXML file');
        }
        return new self($reader);
    }

    public static function fromString(string $xml): self
    {
        $reader = \XMLReader::XML($xml, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        if (!$reader instanceof \XMLReader) {
            throw new \RuntimeException('Cannot read MARCXML');
        }
        return new self($reader);
    }

    /** @return \Generator<int, MarcRecord|MarcReadError> */
    public function getIterator(): \Generator
    {
        $previous = libxml_use_internal_errors(true);
        $position = 0;
        try {
            $more = $this->reader->read();
            while ($more) {
                if ($this->reader->nodeType === \XMLReader::ELEMENT && $this->reader->localName === 'record') {
                    $node = $this->reader->expand();
                    if ($node instanceof \DOMElement && self::isMarcRecord($node)) {
                        $position++;
                        try {
                            yield $position => self::toRecord($node);
                        } catch (MarcParseException $e) {
                            yield $position => new MarcReadError($position, $e->getMessage(), MarcReadError::excerptOf((string) $node->textContent));
                        }
                        $more = $this->reader->next();
                        continue;
                    }
                }
                $more = $this->reader->read();
            }
            $error = libxml_get_last_error();
            if ($error !== false && $error->level >= LIBXML_ERR_ERROR) {
                yield $position + 1 => new MarcReadError(
                    $position + 1,
                    sprintf('XML is not well-formed at line %d: %s', $error->line, trim($error->message))
                );
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $this->reader->close();
        }
    }

    private static function isMarcRecord(\DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && in_array($child->localName, self::MARC_CHILDREN, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A MARCXML / MARCXchange <record> element as a MarcRecord.
     *
     * @throws MarcParseException
     */
    public static function toRecord(\DOMElement $node): MarcRecord
    {
        $record = new MarcRecord(MarcRecord::DEFAULT_LEADER);
        $record->setFormat($node->getAttribute('format'));
        $hasLeader = false;
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            switch ($child->localName) {
                case 'leader':
                    $record->setLeader((string) $child->textContent);
                    $hasLeader = true;
                    break;
                case 'controlfield':
                    $tag = $child->getAttribute('tag');
                    if ($tag === '') {
                        throw new MarcParseException('controlfield without a tag');
                    }
                    $record->addControlField($tag, (string) $child->textContent);
                    break;
                case 'datafield':
                    $tag = $child->getAttribute('tag');
                    if ($tag === '') {
                        throw new MarcParseException('datafield without a tag');
                    }
                    $subfields = [];
                    foreach ($child->childNodes as $sub) {
                        if ($sub instanceof \DOMElement && $sub->localName === 'subfield') {
                            $subfields[] = [$sub->getAttribute('code'), (string) $sub->textContent];
                        }
                    }
                    $record->addDataField($tag, $child->getAttribute('ind1'), $child->getAttribute('ind2'), $subfields);
                    break;
            }
        }
        if (!$hasLeader && $record->fields() === []) {
            throw new MarcParseException('Empty record');
        }
        return $record;
    }
}
