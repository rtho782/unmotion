<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

/** Deterministic XML structure, including namespace bindings. Unlike libxml C14N,
 * this supports Unraid's historical relative xmlns="unraid" without returning
 * false (which must never become an empty/constant authorization hash).
 * Subtree callers intentionally get an independent namespace context.
 */
function unmXmlCanonical(DOMNode $node): string {
    if($node instanceof DOMDocument){$root=$node->documentElement;if(!$root)throw new RuntimeException('Missing XML root.');$node=$root;}
    $copy=new DOMDocument();$copy->appendChild($copy->importNode($node,true));$xp=new DOMXPath($copy);
    // Preserve existing C14N hashes wherever libxml supports the namespace
    // context. The structural representation is only for its relative-URI case.
    $previous=libxml_use_internal_errors(true);
    try{$canonical=$copy->C14N();}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    if(is_string($canonical))return $canonical;
    $walk=static function(DOMNode $n)use(&$walk,$xp): array {
        if($n instanceof DOMElement){
            $attrs=[];foreach($n->attributes as $a)$attrs[]=[$a->namespaceURI??'',$a->nodeName,$a->value];usort($attrs,static fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));
            $namespaces=[];foreach($xp->query('namespace::*',$n) as $ns)$namespaces[$ns->nodeName]=$ns->nodeValue;ksort($namespaces,SORT_STRING);
            $children=[];foreach($n->childNodes as $child){
                if($child->nodeType===XML_COMMENT_NODE)continue;
                $part=$walk($child);$last=count($children)-1;
                if($part[0]==='text'&&$last>=0&&$children[$last][0]==='text')$children[$last][1].=$part[1];else$children[]=$part;
            }
            return ['element',$n->namespaceURI??'',$n->nodeName,$attrs,$namespaces,$children];
        }
        if(in_array($n->nodeType,[XML_TEXT_NODE,XML_CDATA_SECTION_NODE],true))return ['text',$n->nodeValue];
        if($n->nodeType===XML_PI_NODE)return ['pi',$n->nodeName,$n->nodeValue];
        throw new RuntimeException('Unsupported XML node in canonical definition.');
    };
    return json_encode($walk($copy->documentElement),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}
