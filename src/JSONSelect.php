<?php
/**
 * Implements JSONSelectors as described on http://jsonselect.org/
 *
 * Changelog 16/7/2015: chris.thomas@antimatter-studios.com
 * -	Modified heavily to fix a few issues that make it easier to work with
 * -	Implements array access and countable, etc, meaning results from find() can be used in foreach loops
 * -	The constructor takes the document, not the selector, meaning you can construct a document orientated method of working
 * -	removed match(), changed to find(), similar to jquery
 * -	find() now returns a JSONSelect object, meaning you can do similar operators to jquery, such as find elements and then use the results to find() more elements from those results
 * -	changed the selectors so you just use "Name" instead of ".Name" then internally rewrite the selector, this makes a lot more sense than thinking everything is a class and putting that onto the programmer, who might not understand why we're selecting "elements" as "classes".
 * -	renamed the class to JSONSelect to match the name of the class name (a bit more of a standard approach)
 * -	removed the namespace, I don't like it :)
 * -	allow the selector to be passed through on the constructor, needing changes to the find() method that sets internal data based on what was selected, allowing us to override the final document stored
 * -	implement better code for the offsetXXX methods
 * -	implement first() to obtain the first item in a document
 * -	implement text() to obtain all the results as text, it'll convert non-scalar values to gettype strings and only return unique values, also if there is only one value, it'll return it directly, not an array of one value
 *
 * */

class JSONSelect implements Iterator, ArrayAccess, Countable
{
	const VALUE_PLACEHOLDER = "__X__special_value__X__";

	protected $document;
	protected $selector;
	protected $collection;
	protected $keys;
	protected $position = 0;
	
    public function __construct($document=null,$selector=null)
   	{
   		if(!$document && !is_string($document) && !is_array($document) && !is_object($document)){
   			$document = '{}';
   		}else if(is_string($document) && file_exists($document)){
   			$document = file_get_contents($document);
   		}else if(is_array($document)){
   			$document = json_encode($document);
   		}else if(is_object($document)){
   			$document = json_encode((array)$document);
   		}
   		
   		$this->document = json_decode($document);
   		
   		if($selector && !empty($this->document)){
   			$this->find($selector);
   			if(!empty($this->collection)){ 
   				$this->document = $this->collection;
   			}
   		}
   		
   		$this->setKeys();
   	}
   	
   	protected function setKeys()
   	{
   		$this->keys = array_keys((array)$this->document);
   		$this->position = 0;
   	}

	// emitted error codes.
    protected $errorCodes = array(
        "bop" => "binary operator expected",
        "ee"  => "expression expected",
        "epex"=> "closing paren expected ')'",
        "ijs" => "invalid json string",
        "mcp" => "missing closing paren",
        "mepf"=> "malformed expression in pseudo-function",
        "mexp"=> "multiple expressions not allowed",
        "mpc" => "multiple pseudo classes (:xxx) not allowed",
        "nmi" => "multiple ids not allowed",
        "pex" => "opening paren expected '('",
        "se"  => "selector expected",
        "sex" => "string expected",
        "sra" => "string required after '.'",
        "uc"  => "unrecognized char",
        "ucp" => "unexpected closing paren",
        "ujs" => "unclosed json string",
        "upc" => "unrecognized pseudo class"
    );

    // throw an error message
    protected function te($ec, $context)
    {
		throw new Exception($this->errorCodes[$ec] . (" in '" . $context . "'"));
    }

    // THE LEXER
    protected $toks = array(
        'psc' => 1, // pseudo class
        'psf' => 2, // pseudo class function
        'typ' => 3, // type
        'str' => 4, // string
        'ide' => 5  // identifiers (or "classes", stuff after a dot)
    );

    // The primary lexing regular expression in jsonselect
    protected function pat()
    {
        return "/^(?:" .
        // (1) whitespace
        "([\\r\\n\\t\\ ]+)|" .
        // (2) one-char ops
        "([~*,>\\)\\(])|" .
        // (3) types names
        "(string|boolean|null|array|object|number)|" .
        // (4) pseudo classes
        "(:(?:root|first-child|last-child|only-child))|" .
        // (5) pseudo functions
        "(:(?:nth-child|nth-last-child|has|expr|val|contains))|" .
        // (6) bogusly named pseudo something or others
        "(:\\w+)|" .
        // (7 & 8) identifiers and JSON strings
        "(?:(\\.)?(\\\"(?:[^\\\\\\\"]|\\\\[^\\\"])*\\\"))|" .
        // (8) bogus JSON strings missing a trailing quote
        "(\\\")|" .
        // (9) identifiers (unquoted)
        "\\.((?:[_a-zA-Z]|[^\\0-\\0177]|\\\\[^\\r\\n\\f0-9a-fA-F])(?:[\$_a-zA-Z0-9\\-]|[^\\x{0000}-\\x{0177}]|(?:\\\\[^\\r\\n\\f0-9a-fA-F]))*)" .
        ")/u";
    }

    // A regular expression for matching "nth expressions" (see grammar, what :nth-child() eats)
    protected $nthPat = '/^\s*\(\s*(?:([+\-]?)([0-9]*)n\s*(?:([+\-])\s*([0-9]))?|(odd|even)|([+\-]?[0-9]+))\s*\)/';

    protected function lex($str, $off)
    {
        if (!$off) $off = 0;
        //var m = pat.exec(str.substr(off));
        preg_match($this->pat(), substr($str, $off), $m);

        //echo "lex from $off ".print_r($m,true)."\n";

        if (!$m) return null;
        $off+=strlen($m[0]);

        $a = null;
        if (($m[1])) $a = array($off, " ");
        else if (($m[2])) $a = array($off, $m[0]);
        else if (($m[3])) $a = array($off, $this->toks['typ'], $m[0]);
        else if (($m[4])) $a = array($off, $this->toks['psc'], $m[0]);
        else if (($m[5])) $a = array($off, $this->toks['psf'], $m[0]);
        else if (($m[6])) $this->te("upc", $str);
        else if (($m[8])) $a = array($off, $m[7] ? $this->toks['ide'] : $this->toks['str'], json_decode($m[8]));
        else if (($m[9])) $this->te("ujs", $str);
        else if (($m[10])) $a = array($off, $this->toks['ide'], preg_replace('/\\\\([^\r\n\f0-9a-fA-F])/','$1',$m[10]));

        return $a;
    }

    // THE EXPRESSION SUBSYSTEM

    protected function exprPat()
    {
		return
            // skip and don't capture leading whitespace
            "/^\\s*(?:" .
            // (1) simple vals
            "(true|false|null)|" .
            // (2) numbers
            "(-?\\d+(?:\\.\\d*)?(?:[eE][+\\-]?\\d+)?)|" .
            // (3) strings
            "(\"(?:[^\\]|\\[^\"])*\")|" .
            // (4) the 'x' value placeholder
            "(x)|" .
            // (5) binops
            "(&&|\\|\\||[\\$\\^<>!\\*]=|[=+\\-*\\/%<>])|" .
            // (6) parens
            "([\\(\\)])" .
            ")/";
    }

    protected function operator($op,$ix)
    {
        $operators = array(
            '*' =>  array( 9, function($lhs, $rhs) { return $lhs * $rhs; } ),
            '/' =>  array( 9, function($lhs, $rhs) { return $lhs / $rhs; } ),
            '%' =>  array( 9, function($lhs, $rhs) { return $lhs % $rhs; } ),
            '+' =>  array( 7, function($lhs, $rhs) { return $lhs + $rhs; } ),
            '-' =>  array( 7, function($lhs, $rhs) { return $lhs - $rhs; } ),
            '<=' =>  array( 5, function($lhs, $rhs) { return is_numeric($lhs) && is_numeric($rhs) && $lhs <= $rhs || is_string($lhs) && is_string($rhs) && strcmp($lhs, $rhs) <= 0; } ),
            '>=' =>  array( 5, function($lhs, $rhs) { return is_numeric($lhs) && is_numeric($rhs) && $lhs >= $rhs || is_string($lhs) && is_string($rhs) && strcmp($lhs,$rhs) >= 0; } ),
            '$=' =>  array( 5, function($lhs, $rhs) { return is_string($lhs) && is_string($rhs) && strrpos($lhs, $rhs) === strlen($lhs) - strlen($rhs); } ),
            '^=' =>  array( 5, function($lhs, $rhs) { return is_string($lhs) && is_string($rhs) && strpos($lhs, $rhs) === 0; } ),
            '*=' =>  array( 5, function($lhs, $rhs) { return is_string($lhs) && is_string($rhs) && strpos($lhs, $rhs) !== false; } ),
            '>' =>  array( 5, function($lhs, $rhs) { return is_numeric($lhs) && is_numeric($rhs) && $lhs > $rhs || is_string($lhs) && is_string($rhs) && strcmp($lhs,$rhs) > 0; } ),
            '<' =>  array( 5, function($lhs, $rhs) { return is_numeric($lhs) && is_numeric($rhs) && $lhs < $rhs || is_string($lhs) && is_string($rhs) && strcmp($lhs,$rhs) < 0; } ),
            '=' =>  array( 3, function($lhs, $rhs) { return $lhs === $rhs; } ),
            '!=' =>  array( 3, function($lhs, $rhs) { return $lhs !== $rhs; } ),
            '&&' =>  array( 2, function($lhs, $rhs) { return $lhs && $rhs; } ),
            '||' =>  array( 1, function($lhs, $rhs) { return $lhs || $rhs; })
        );
        return $operators[$op][$ix];
    }

    protected function exprLex($str, $off)
    {
        //var v, m = exprPat.exec(str.substr(off));
        $v = null;
        preg_match($this->exprPat(), substr($str, $off), $m);
        if ($m) {
            $off += strlen($m[0]);
            //$v = $m[1] || $m[2] || $m[3] || $m[5] || $m[6];
            foreach(array(1,2,3,5,6) as $k){
                if(isset($m[$k]) && strlen($m[$k])>0){
                    $v = $m[$k];
                    break;
                }
            }

            if (strlen($m[1]) || strlen($m[2]) || strlen($m[3])) return array($off, 0, json_decode($v));
            else if (strlen($m[4])) return array($off, 0, self::VALUE_PLACEHOLDER);
            return array($off, $v);
        }
    }

    protected function exprParse2($str, $off)
    {
        if (!$off) $off = 0;
        // first we expect a value or a '('
        $l = $this->exprLex($str, $off);
        //echo "exprLex ".print_r($l,true);
        $lhs=null;

        if ($l && $l[1] === '(') {
            $lhs = $this->exprParse2($str, $l[0]);
            $p = $this->exprLex($str, $lhs[0]);

            //echo "exprLex2 ".print_r($p,true);

            if (!$p || $p[1] !== ')') $this->te('epex', $str);
            $off = $p[0];
            $lhs = [ '(', $lhs[1] ];
        } else if (!$l || ($l[1] && $l[1] != 'x')) {
            $this->te("ee", $str . " - " . ( $l[1] && $l[1] ));
        } else {
            $lhs = (($l[1] === 'x') ? self::VALUE_PLACEHOLDER : $l[2]);
            $off = $l[0];
        }

        // now we expect a binary operator or a ')'
        $op = $this->exprLex($str, $off);

        //echo "exprLex3 ".print_r($op,true);
        if (!$op || $op[1] == ')'){
        	return array($off, $lhs);
        }else if ($op[1] == 'x' || !$op[1]){
            $this->te('bop', $str . " - " . ( $op[1] && $op[1] ));
        }

        // tail recursion to fetch the rhs expression
        $rhs = $this->exprParse2($str, $op[0]);
        $off = $rhs[0];
        $rhs = $rhs[1];

        // and now precedence!  how shall we put everything together?
        $v = null;
        if ((!is_object($rhs) && !is_array($rhs)) || $rhs[0] === '(' || $this->operator($op[1],0) < $this->operator($rhs[1],0) ) {
            $v = array($lhs, $op[1], $rhs);
        } else {
            // TODO: fix this, prob related due to $v copieeing $rhs instead of referencing
            //echo "re-arrange lhs:".print_r($lhs,true).' rhs: '.print_r($rhs,true);
            //print_r($rhs);

            $v = &$rhs;
            while (is_array($rhs[0]) && $rhs[0][0] != '(' && $this->operator($op[1],0) >= $this->operator($rhs[0][1],0)) {
                $rhs = &$rhs[0];
            }
            $rhs[0] = array($lhs, $op[1], $rhs[0]);
        }

        return array($off, $v);
    }


	protected function deparen($v)
	{
    	if ( (!is_object($v) && !is_array($v)) || $v === null){
    		return $v;
    	}else if ($v[0] === '('){
    		return $this->deparen($v[1]);
    	}

    	return array($this->deparen($v[0]), $v[1], $this->deparen($v[2]));
	}


    protected function exprParse($str, $off)
    {
        $e = $this->exprParse2($str, $off ? $off : 0);

        return array($e[0], $this->deparen($e[1]));
    }

    protected function exprEval($expr, $x)
    {
        if ($expr === self::VALUE_PLACEHOLDER){
        	return $x;
        }else if ($expr === null || (!is_object($expr) && !is_array($expr))){
            return $expr;
        }

        $lhs = $this->exprEval($expr[0], $x);
        $rhs = $this->exprEval($expr[2], $x);
        $op = $this->operator($expr[1],1);

        return $op($lhs, $rhs);
    }

    // THE PARSER

    protected function parse($str, $off=0, $nested=null, $hints=null)
    {
        if (!$nested) $hints = array();
        
        $str = implode(" ",array_map(function($str){ return ".".trim($str,"."); },explode(" ",$str)));

        $a = array();
        $am=null;
        $readParen=null;
        if (!$off) $off = 0;

        while (true) {
            //echo "parse round @$off\n";
            $s = $this->parse_selector($str, $off, $hints);
            $a [] = $s[1];
            $s = $this->lex($str, $off = $s[0]);
            //echo "next lex @$off ";
            //print_r($s);
            if ($s && $s[1] === " ") $s = $this->lex($str, $off = $s[0]);
            //echo "next lex @$off ";
            if (!$s) break;
            // now we've parsed a selector, and have something else...
            if ($s[1] === ">" || $s[1] === "~") {
                if ($s[1] === "~") $hints['usesSiblingOp'] = true;
                $a []= $s[1];
                $off = $s[0];
            } else if ($s[1] === ",") {
                if ($am === null) $am = [ ",", $a ];
                else $am []= $a;
                $a = [];
                $off = $s[0];
            } else if ($s[1] === ")") {
                if (!$nested) $this->te("ucp", $s[1]);
                $readParen = 1;
                $off = $s[0];
                break;
            }
        }

        if ($nested && !$readParen){
        	$this->te("mcp", $str);
        }

        if ($am){
        	$am []= $a;
        }

        $rv;

        if (!$nested && isset($hints['usesSiblingOp'])) {
            $rv = $this->normalize($am ? $am : $a);
        } else {
            $rv = $am ? $am : $a;
        }

        return array($off, $rv);
    }

    protected function normalizeOne($sel)
    {
        $sels = array();
        $s=null;

        for ($i = 0; $i < sizeof($sel); $i++)
        {
            if ($sel[$i] === '~')
            {
                // `A ~ B` maps to `:has(:root > A) > B`
                // `Z A ~ B` maps to `Z :has(:root > A) > B, Z:has(:root > A) > B`
                // This first clause, takes care of the first case, and the first half of the latter case.
                if ($i < 2 || $sel[$i-2] != '>') {
                    $s = array_slice($sel,0,$i-1);
                    $s []= array('has'=>array(array(array('pc'=> ":root"), ">", $sel[$i-1] )));
                    $s []= ">";
                    $s = array_merge($s, array_slice($sel, $i+1));
                    $sels []= $s;
                }
                // here we take care of the second half of above:
                // (`Z A ~ B` maps to `Z :has(:root > A) > B, Z :has(:root > A) > B`)
                // and a new case:
                // Z > A ~ B maps to Z:has(:root > A) > B
                if ($i > 1) {
                    $at = $sel[$i-2] === '>' ? $i-3 : $i-2;
                    $s = array_slice($sel,0,$at);
                    $z = array();

                    foreach($sel[$at] as $k => $v){ $z[$k] = $v; }

                    if (!isset($z['has'])) $z['has'] = array();
                    $z['has'] []= array(  array('pc'=> ":root"), ">", $sel[$i-1]);

                    $s = array_merge($s, array($z, '>'), array_slice($sel, $i+1)  );
                    $sels  []= $s;
                }

                break;
            }
        }

        if ($i == sizeof($sel)){
        	return $sel;
        }

        return sizeof($sels) > 1 ? array_merge(array(','), $sels) : $sels[0];
    }

    protected function normalize($sels)
    {
        if ($sels[0] === ',') {
            $r = array(",");

            for ($i = 0; $i < sizeof($sels); $i++) {
                $s = $this->normalizeOne($s[$i]);
                $r = array_merge($r,  $s[0] === "," ? array_slice($s,1) : $s);
            }

            return $r;
        } else {
            return $this->normalizeOne($sels);
        }
    }

    protected function parse_selector($str, $off, $hints)
    {
        $soff = $off;
        $s = array();
        $l = $this->lex($str, $off);

        //echo "parse_selector:1 @$off ".print_r($l,true)."\n";

        // skip space
        if ($l && $l[1] === " "){
        	$soff = $off = $l[0];
        	$l = $this->lex($str, $off);
        }

        if ($l && $l[1] === $this->toks['typ']) {
            $s['type'] = $l[2];
            $l = $this->lex($str, ($off = $l[0]));
        } else if ($l && $l[1] === "*") {
            // don't bother representing the universal sel, '*' in the
            // parse tree, cause it's the default
            $l = $this->lex($str, ($off = $l[0]));
        }

        // now support either an id or a pc
        while (true) {
            //echo "parse_selector:1 @$off  ".print_r($l,true)."\n";
            if ($l === null) {
                break;
            } else if ($l[1] === $this->toks['ide']) {
                if (!empty($s['id'])) $this->te("nmi", $l[1]);
                $s['id'] = $l[2];
            } else if ($l[1] === $this->toks['psc']) {
				if ((isset($s['pc']) && $s['pc']) || (isset($s['pf']) && $s['pf'])) {
					$this->te("mpc", $l[1]);
				}
                // collapse first-child and last-child into nth-child expressions
                if ($l[2] === ":first-child") {
                    $s['pf'] = ":nth-child";
                    $s['a'] = 0;
                    $s['b'] = 1;
                } else if ($l[2] === ":last-child") {
                    $s['pf'] = ":nth-last-child";
                    $s['a'] = 0;
                    $s['b'] = 1;
                } else {
                    $s['pc'] = $l[2];
                }
            } else if ($l[1] === $this->toks['psf']) {
                if ($l[2] === ":val" || $l[2] === ":contains") {
                    $s['expr'] = array(self::VALUE_PLACEHOLDER, $l[2] === ":val" ? "=" : "*=", null);
                    // any amount of whitespace, followed by paren, string, paren
                    $l = $this->lex($str, ($off = $l[0]));
                    if ($l && $l[1] === " ") $l = $this->lex($str, $off = $l[0]);
                    if (!$l || $l[1] !== "(") $this->te("pex", $str);
                    $l = $this->lex($str, ($off = $l[0]));
                    if ($l && $l[1] === " ") $l = $this->lex($str, $off = $l[0]);
                    if (!$l || $l[1] !== $this->toks['str']) $this->te("sex", $str);
                    $s['expr'][2] = $l[2];
                    $l = $this->lex($str, ($off = $l[0]));
                    if ($l && $l[1] === " ") $l = $this->lex($str, $off = $l[0]);
                    if (!$l || $l[1] !== ")") $this->te("epex", $str);
                } else if ($l[2] === ":has") {
                    // any amount of whitespace, followed by paren
                    $l = $this->lex($str, ($off = $l[0]));
                    if ($l && $l[1] === " ") $l = $this->lex($str, $off = $l[0]);
                    if (!$l || $l[1] !== "(") $this->te("pex", $str);
                    $h = $this->parse($str, $l[0], true);
                    $l[0] = $h[0];
                    if (!isset($s['has'])) $s['has'] = array();
                    $s['has'] []= $h[1];
                } else if ($l[2] === ":expr") {
                    if (isset($s['expr'])) $this->te("mexp", $str);
                    $e = $this->exprParse($str, $l[0]);
                    $l[0] = $e[0];
                    $s['expr'] = $e[1];
                } else {
                    if (isset($s['pc']) || isset($s['pf']) ) $this->te("mpc", $str);
                    $s['pf'] = $l[2];
                    //m = nthPat.exec(str.substr(l[0]));
                    preg_match($this->nthPat, substr($str, $l[0]), $m);


                    if (!$m) $this->te("mepf", $str);
                    if (isset($m[5]) && strlen($m[5])>0) {
                        $s['a'] = 2;
                        $s['b'] = ($m[5] === "odd") ? 1 : 0;
                    } else if (isset($m[6]) && strlen($m[6])>0) {
                        $s['a'] = 0;
                        $s['b'] = (int)$m[6];
                    } else {
                        $s['a'] = (int)(($m[1] ? $m[1] : "+") . ($m[2] ? $m[2] : "1"));
                        $s['b'] = $m[3] ? (int)($m[3] + $m[4]) : 0;
                    }
                    $l[0] += strlen($m[0]);
                }
            } else {
                break;
            }
            $l = $this->lex($str, ($off = $l[0]));
        }

        // now if we didn't actually parse anything it's an error
        if ($soff === $off) $this->te("se", $str);
        //echo "parsed ";
        //print_r($s);
        return array($off, $s);
    }

    // THE EVALUATOR

    protected function mytypeof($o)
    {
        if($o === null) return "null";
        if(is_object($o)) return "object";
        if(is_array($o)) return "array";
        if(is_numeric($o)) return "number";
        if($o===true || $o==false) return "boolean";
        
        return "string";
    }

    protected function mn($node, $sel, $id, $num, $tot)
    {
        //echo "match on $num/$tot\n";
        $sels = array();
        $cs = ($sel[0] === ">") ? $sel[1] : $sel[0];
        $m = true;
        $mod = null;
        if (isset($cs['type'])){
        	$m = $m && ($cs['type'] === $this->mytypeof($node));
        }

        if (isset($cs['id'])){
        	$m = $m && ($cs['id'] === $id);
        }

        if ($m && isset($cs['pf'])) {
            if($num===null) $num = null;
            else if ($cs['pf'] === ":nth-last-child") $num = $tot - $num;
            else $num++;

            if ($cs['a'] === 0) {
                $m = $cs['b'] === $num;
            } else if($num!==null){
                $mod = (($num - $cs['b']) % $cs['a']);

                $m = (!$mod && (($num*$cs['a'] + $cs['b']) >= 0));

            }else {
                $m = false;
            }
        }

        if ($m && isset($cs['has'])) {
            // perhaps we should augment forEach to handle a return value
            // that indicates "client cancels traversal"?
            //var bail = function() { throw 42; };
            for ($i = 0; $i < sizeof($cs['has']); $i++) {
                //echo "select for has ".print_r($cs['has'],true);
                $res = $this->collect($cs['has'][$i], $node, null, null, null, true );
                if(sizeof($res)>0){
                    //echo " => ".print_r($res, true);
                    //echo " on ".print_r($node, true);
                    continue;
                }
                //echo "blaaaa \n";
                $m = false;
                break;
            }
        }

        if ($m && isset($cs['expr'])) {
            $m = $this->exprEval($cs['expr'], $node);
        }

        // should we repeat this selector for descendants?
        if ($sel[0] !== ">" && (!isset($sel[0]['pc']) || $sel[0]['pc'] !== ":root")) {
			$sels []= $sel;
		}

        if ($m) {
            // is there a fragment that we should pass down?
            if ($sel[0] === ">") {
                if (sizeof($sel) > 2) { $m = false; $sels []= array_slice($sel,2); }
            }
            else if (sizeof($sel) > 1) { $m = false; $sels []= array_slice($sel,1); }
        }
        //echo "MATCH? ";
        //echo print_r($node,true);
        //echo $m ? "YES":"NO";
        //echo "\n";
        return array($m, $sels);
    }

    protected function collect($sel, $obj, $collector=null, $id=null, $num=null, $tot=null, $returnFirst=false)
    {
        if(!$collector) $collector = array();

        $a = ($sel[0] === ",") ? array_slice($sel, 1) : array($sel);
        $a0 = array();
        $call = false;
        $i = 0;
        $j = 0;
        $k = 0;
        $x = 0;
        for ($i = 0; $i < sizeof($a); $i++) {
            $x = $this->mn($obj, $a[$i], $id, $num, $tot);
            if ($x[0]) {
                $call = true;
                if($returnFirst) return array($obj);
            }
            for ($j = 0; $j < sizeof($x[1]); $j++) {
                $a0 []= $x[1][$j];
            }
        }

        if (sizeof($a0)>0 && ( is_array($obj) || is_object($obj) ) ) {
            if (sizeof($a0) >= 1) {
                array_unshift($a0, ",");
            }
            if(is_array($obj)){
                $_tot = sizeof($obj);
                //echo "iterate $_tot\n";
                foreach ($obj as $k=>$v) {
                    $collector = $this->collect($a0, $v, $collector, null, $k, $_tot, $returnFirst);
                    if($returnFirst && sizeof($collector)>0) return $collector;
                }
            }else{
                foreach ($obj as $k=>$v) {
                    $collector = $this->collect($a0, $v, $collector, $k, null, null, $returnFirst);
                    if($returnFirst && sizeof($collector)>0) return $collector;
                }
            }
        }

        if($call){
            $collector []= $obj;
        }

        return $collector;
    }
    
    public function offsetSet($offset, $value)
    {
    	if (is_null($offset)) {
    		$c = count((array)$this->document);
    		$this->document->{$c} = $value;
    	} else {
    		$this->document->$offset = $value;
    	}
    	
    	$this->setKeys();
    }
    
    public function offsetExists($offset)
    {
    	return isset($this->document->$offset);
    }
    
    public function offsetUnset($offset)
    {
    	unset($this->document->$offset);
    }
    
    public function offsetGet($offset)
    {
    	$d = $this->document;
    	
    	$current = null;
    	
    	if($d instanceof stdClass){
    		$current = isset($d->$offset) ? $d->offset : null;
    	}else if(is_array($d)){
    		$current = isset($d[$offset]) ? $d[$offset] : null;
    	}
    	
    	return is_scalar($current) ? $current : new JSONSelect($current);
    }
    
    public function first()
    {
    	return $this[$this->keys[0]];
    }
    
    public function text()
    {
    	$results = array();
    	
    	if(!empty($this->document)){
    		foreach($this->document as $key=>$value){
    			$results[$key] = is_scalar($value) ? $value : gettype($value);
    		}
    	}
    	
    	$results = array_unique($results);
    	
    	return count($results) == 1 ? current($results) : (empty($results) ? "" : $results);
    }
    
    public function count()
    {
    	return count($this->keys);
    }
    
    function rewind()
    {
    	$this->position = 0;
    }
    
    function current()
    {
    	$k = $this->key();
    	
    	$current = $this->document instanceof stdClass ? $this->document->{$k} : $this->document[$k];
    	
    	return new JSONSelect($current);
    }
    
    function key()
    {
    	return $this->keys[$this->position];
    }
    
    function next()
    {
    	++$this->position;
    }
    
    function valid()
    {
    	return array_key_exists($this->position,$this->keys);
    }

    public function find($selector)
    {
    	if(empty($selector)){
    		$this->collection = null;
    	}else{
   			$this->selector		=	$this->parse($selector);
   			$this->collection	=	$this->collect($this->selector[1], $this->document);
   			
   			//	Not sure whether this is good to do all the time, or just in specific circumstances
   			if(count($this->collection) == 1 && !is_scalar($this->collection[0])){
   				$this->collection = current($this->collection);
   			}
    	}
    	
        return new JSONSelect($this->collection);
    }
}
