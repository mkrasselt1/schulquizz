<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Macht SVG-Grafiken (z. B. von KI-Assistenten gezeichnete Raumbilder) sicher.
 *
 * Strenge Positivliste: nur Zeichen-Elemente und Darstellungs-Attribute bleiben übrig.
 * Kein <script>, kein <foreignObject>, kein <image>, kein <style>, keine Event-Handler,
 * keine externen Verweise (href/url() nur auf "#id" innerhalb der Datei), keine DOCTYPE/Entities.
 * Damit kann die Datei auch beim direkten Öffnen im Browser keinen Code ausführen.
 */
class SvgSanitizer
{
    public const MAX_BYTES = 512 * 1024;

    private const SVG_NS = 'http://www.w3.org/2000/svg';

    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    private const ELEMENTS = [
        'svg', 'g', 'defs', 'title', 'desc', 'symbol', 'use',
        'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'path',
        'text', 'tspan', 'textPath',
        'linearGradient', 'radialGradient', 'stop', 'pattern', 'clipPath', 'mask',
        'filter', 'feGaussianBlur', 'feOffset', 'feBlend', 'feFlood', 'feComposite',
        'feMerge', 'feMergeNode', 'feDropShadow', 'feColorMatrix',
    ];

    private const ATTRIBUTES = [
        'id', 'class', 'style', 'version', 'viewBox', 'preserveAspectRatio', 'width', 'height',
        'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'dx', 'dy',
        'd', 'points', 'pathLength', 'transform', 'rotate', 'textLength', 'lengthAdjust', 'startOffset',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity',
        'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-miterlimit',
        'opacity', 'visibility', 'display', 'overflow', 'paint-order', 'vector-effect',
        'clip-path', 'clip-rule', 'clipPathUnits', 'mask', 'maskUnits', 'maskContentUnits', 'filter',
        'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant', 'text-anchor',
        'dominant-baseline', 'alignment-baseline', 'baseline-shift', 'letter-spacing', 'word-spacing',
        'text-decoration', 'writing-mode',
        'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform', 'spreadMethod',
        'patternUnits', 'patternContentUnits', 'patternTransform',
        'filterUnits', 'primitiveUnits', 'stdDeviation', 'in', 'in2', 'result', 'mode', 'operator',
        'k1', 'k2', 'k3', 'k4', 'flood-color', 'flood-opacity', 'type', 'values',
        'href', // nur interne Verweise, siehe safeValue()
    ];

    /** Bereinigtes SVG oder null, wenn die Eingabe kein brauchbares SVG ist. */
    public static function clean(string $svg): ?string
    {
        if ($svg === '' || strlen($svg) > self::MAX_BYTES || stripos($svg, '<!DOCTYPE') !== false || stripos($svg, '<!ENTITY') !== false) {
            return null;
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->documentElement;
        if (! $ok || $doc->doctype !== null || ! $root || $root->localName !== 'svg' || $root->namespaceURI !== self::SVG_NS) {
            return null;
        }

        self::cleanNode($root);

        $out = new DOMDocument('1.0', 'UTF-8');
        $out->appendChild($out->importNode($root, true));
        $xml = $out->saveXML();

        return $xml === false ? null : $xml;
    }

    private static function cleanNode(DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = $attr->localName;
            $ns = $attr->namespaceURI;
            $allowed = ($ns === null || $ns === '') && in_array($name, self::ATTRIBUTES, true)
                || $ns === self::XLINK_NS && $name === 'href';
            if (! $allowed || ! self::safeValue($name, $attr->value)) {
                $el->removeAttributeNode($attr);
            }
        }

        foreach (iterator_to_array($el->childNodes) as $child) {
            /** @var DOMNode $child */
            if ($child instanceof DOMElement) {
                if ($child->namespaceURI === self::SVG_NS && in_array($child->localName, self::ELEMENTS, true)) {
                    self::cleanNode($child);
                } else {
                    $el->removeChild($child);
                }
            } elseif (! in_array($child->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true)) {
                // Kommentare, Processing-Instructions, Entity-Referenzen …
                $el->removeChild($child);
            }
        }
    }

    private static function safeValue(string $name, string $value): bool
    {
        if ($name === 'href') {
            return (bool) preg_match('/^#[A-Za-z0-9_.:-]+$/', $value);
        }
        $lower = strtolower($value);
        foreach (['javascript:', 'data:', 'expression', '@import', '<', '\\'] as $bad) {
            if (str_contains($lower, $bad)) {
                return false;
            }
        }
        // url(...) nur auf interne IDs, z. B. fill="url(#verlauf)"
        if (preg_match_all('/url\s*\(([^)]*)\)/i', $value, $m)) {
            foreach ($m[1] as $target) {
                if (! preg_match('/^\s*["\']?#[A-Za-z0-9_.:-]+["\']?\s*$/', $target)) {
                    return false;
                }
            }
        }

        return true;
    }
}
