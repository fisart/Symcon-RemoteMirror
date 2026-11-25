<?php
declare(strict_types=1);

/** Static lifecycle slice: keep persistent configuration writes and their control/data inputs.
 * Native exporter/importer methods are never sliced. No inspected code is executed.
 */
final class MCBConfigurationScope
{
    public static function persistent(array $tokens,array $attributes): bool
    {
        foreach ($tokens as $i=>$t) {
            if ($t->id!==T_STRING || ($tokens[$i+1]->text??'')!=='(') continue;
            $name=strtolower($t->text);
            if (str_starts_with($name,'registerproperty') || in_array($name,['ips_setproperty','ips_setconfiguration'],true)) return true;
            $member=in_array($tokens[$i-1]->id??null,[T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR,T_DOUBLE_COLON],true);
            if (!$member && (in_array($name,['requestaction','ips_runscript','ips_runscriptex','ips_runscripttext','ips_runscripttextex'],true) || preg_match('/^setvalue(boolean|integer|float|string)?$/D',$name))) return true;
            if ($attributes && (str_starts_with($name,'registerattribute') || str_starts_with($name,'writeattribute'))) {
                $arg=$tokens[$i+2]??null;
                if ($arg===null || $arg->id!==T_CONSTANT_ENCAPSED_STRING || in_array(substr($arg->text,1,-1),$attributes,true)) return true;
            }
        }
        return false;
    }
    public static function calls(array $tokens): array
    {
        $out=[];
        foreach ($tokens as $i=>$t) {
            if ($t->id===T_STRING && ($tokens[$i+1]->text??'')==='(' && in_array($tokens[$i-1]->id??null,[T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR,T_DOUBLE_COLON],true) && !(($tokens[$i-1]->id??null)===T_DOUBLE_COLON && strtolower($tokens[$i-2]->text??'')==='parent')) $out[strtolower($t->text)]=true;
            elseif ($t->id===T_STRING && ($tokens[$i+1]->text??'')==='(' && !in_array($tokens[$i-1]->id??null,[T_FUNCTION,T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR,T_DOUBLE_COLON,T_NEW],true)) $out['@'.strtolower($t->text)]=true;
            elseif ($t->id===T_CONSTANT_ENCAPSED_STRING && (str_contains($t->text,'::') || (($tokens[$i-1]->text??'')===',' && ($tokens[$i-2]->text??'')==='$this'))) {
                $name=strtolower(substr($t->text,1,-1));$out[substr($name,strrpos('::'.$name,'::'))]=true;
            }
        }
        return $out;
    }
    /** Dynamic lifecycle dispatch cannot be proved configuration-independent. */
    private static function opaque(array $tokens): bool
    {
        foreach($tokens as $t) {
            if($t->text==='{')break;
            if(in_array($t->id,[T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG],true))return true;
        }
        $closures=[];$other=[];
        foreach ($tokens as $i=>$t) if ($t->id===T_VARIABLE && ($tokens[$i+1]->text??'')==='=') {
            $j=$i+2;if (($tokens[$j]->id??null)===T_STATIC) ++$j;
            if (in_array($tokens[$j]->id??null,[T_FN,T_FUNCTION],true)) $closures[$t->text]=true;else $other[$t->text]=true;
        }
        $closures=array_diff_key($closures,$other);
        foreach ($tokens as $i=>$t) {
            if (($t->id===T_OBJECT_OPERATOR || $t->id===T_NULLSAFE_OBJECT_OPERATOR || $t->id===T_DOUBLE_COLON) && ($tokens[$i+1]->id??null)!==T_STRING && (($tokens[$i+2]->text??'')==='(' || ($tokens[$i+1]->text??'')==='{')) return true;
            if ($t->id===T_VARIABLE && ($tokens[$i+1]->text??'')==='(' && !isset($closures[$t->text])) return true;
            if ($t->id===T_EVAL || ($t->id===T_STRING && (str_starts_with(strtolower($t->text),'reflection') || in_array(strtolower($t->text),['call_user_func','call_user_func_array','forward_static_call','forward_static_call_array'],true)))) return true;
            // Mutable object state and reference APIs can feed later registration without an explicit return value.
            // Keep the complete lifecycle helper rather than silently dropping those dependencies.
            if (in_array($t->id,[T_OBJECT_OPERATOR,T_DOUBLE_COLON],true) && in_array($tokens[$i+2]->text??'',['=','+=','-=','??=','++','--'],true)) return true;
        }
        return false;
    }
    public static function sensitive(array $methods,array $attributes): array
    {
        $out=[];$calls=[];
        foreach ($methods as $key=>$tokens) {
            $calls[$key]=self::calls($tokens);
            if (self::persistent($tokens,$attributes) || self::opaque($tokens)) $out[substr($key,strrpos($key,'::')+2)]=true;
            foreach($tokens as $t) {
                if($t->text==='{')break;
                if(in_array($t->id,[T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG],true))$out[substr($key,strrpos($key,'::')+2)]=true;
            }
        }
        do {
            $before=count($out);
            foreach ($calls as $key=>$names) if (array_intersect_key($names,$out)) $out[substr($key,strrpos($key,'::')+2)]=true;
        } while (count($out)!==$before);
        return $out;
    }
    private static function matching(array $t,int $i,string $open,string $close): int
    {
        $depth=0;
        for ($n=count($t);$i<$n;$i++) { if ($t[$i]->text===$open) ++$depth;elseif ($t[$i]->text===$close && --$depth===0) return $i; }
        throw new RuntimeException('Incomplete lifecycle statement.');
    }
    private static function sequence(array $t,int &$i,int $end): array
    {
        $out=[];while ($i<$end) $out[]=self::statement($t,$i);return $out;
    }
    private static function statement(array $t,int &$i): array
    {
        $start=$i;$id=$t[$i]->id;
        if ($t[$i]->text==='{') {
            $end=self::matching($t,$i,'{','}');++$i;$children=self::sequence($t,$i,$end);++$i;
            return ['head'=>[$t[$start]],'children'=>$children,'tail'=>[$t[$end]]];
        }
        if (in_array($id,[T_IF,T_ELSEIF,T_FOREACH,T_FOR,T_WHILE,T_CATCH],true)) {
            if (($t[$i+1]->text??'')!=='(') throw new RuntimeException('Unsupported lifecycle control.');
            $end=self::matching($t,$i+1,'(',')');$head=array_slice($t,$i,$end-$i+1);$i=$end+1;
            if (($t[$i]->text??'')===':') throw new RuntimeException('Alternative lifecycle syntax requires review.');
            $children=[self::statement($t,$i)];
            if (in_array($id,[T_IF,T_ELSEIF],true) && in_array($t[$i]->id??null,[T_ELSE,T_ELSEIF],true)) {
                if ($t[$i]->id===T_ELSE) { $else=$t[$i++];$children[]=['head'=>[$else],'children'=>[self::statement($t,$i)],'tail'=>[]]; }
                else $children[]=self::statement($t,$i);
            }
            return ['head'=>$head,'children'=>$children,'tail'=>[]];
        }
        if ($id===T_TRY) {
            ++$i;$children=[self::statement($t,$i)];
            while (in_array($t[$i]->id??null,[T_CATCH,T_FINALLY],true)) {
                if ($t[$i]->id===T_FINALLY) { $finally=$t[$i++];$children[]=['head'=>[$finally],'children'=>[self::statement($t,$i)],'tail'=>[]]; }
                else $children[]=self::statement($t,$i);
            }
            return ['head'=>[$t[$start]],'children'=>$children,'tail'=>[]];
        }
        if (in_array($id,[T_SWITCH,T_DO,T_GOTO],true)) throw new RuntimeException('Complex lifecycle control requires full-source review.');
        $depth=0;
        for ($n=count($t);$i<$n;$i++) {
            $v=$t[$i];
            if ($depth===0 && $v->text===';') { ++$i;return ['tokens'=>array_slice($t,$start,$i-$start)]; }
            if (in_array($v->text,['(','[','{'],true) || in_array($v->id,[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true)) ++$depth;
            elseif (in_array($v->text,[')',']','}'],true)) --$depth;
        }
        throw new RuntimeException('Unsupported lifecycle statement.');
    }
    private static function variables(array $tokens): array
    {
        $v=[];foreach ($tokens as $t) if ($t->id===T_VARIABLE && $t->text!=='$this') $v[$t->text]=true;return $v;
    }
    private static function select(array &$node,array &$live,array $sensitive,array $attributes,bool $hasPersistent): bool
    {
        if (isset($node['tokens'])) {
            $t=$node['tokens'];$keep=self::persistent($t,$attributes) || (bool)array_intersect_key(self::calls($t),$sensitive) || self::opaque($t);
            if(array_intersect_key(self::variables($t),$live)) foreach($t as $i=>$v) if($v->id===T_STRING && ($t[$i+1]->text??'')==='(' && in_array(strtolower($v->text),['array_walk','array_walk_recursive','sort','rsort','asort','arsort','ksort','krsort','usort','uasort','uksort','shuffle','parse_str','extract'],true))$keep=true;
            foreach ($t as $i=>$v) {
                if ($v->id===T_VARIABLE && isset($live[$v->text])) {
                    $j=$i+1;while (($t[$j]->text??'')==='[') $j=self::matching($t,$j,'[',']')+1;
                    if (in_array($t[$j]->text??'',['=','+=','-=','??=','++','--'],true)) $keep=true;
                }
                if ($hasPersistent && in_array($v->id,[T_RETURN,T_THROW,T_EXIT,T_BREAK,T_CONTINUE],true)) $keep=true;
            }
            $node['keep']=($node['keep']??false)||$keep;
            if ($node['keep']) $live+=self::variables($t);
            return $node['keep'];
        }
        $keep=false;
        for ($i=count($node['children'])-1;$i>=0;$i--) $keep=self::select($node['children'][$i],$live,$sensitive,$attributes,$hasPersistent)||$keep;
        $head=['tokens'=>$node['head']];$keep=self::select($head,$live,$sensitive,$attributes,false)||$keep;
        // Else/catch branches share control with a selected branch. Preserve control-flow exits.
        $node['keep']=($node['keep']??false)||$keep;
        if ($node['keep']) $live+=self::variables($node['head']);
        return $node['keep'];
    }
    private static function render(array $node,bool $skeleton=false): array
    {
        if (empty($node['keep']) && !$skeleton) return [];
        if (isset($node['tokens'])) return !empty($node['keep'])?$node['tokens']:[new PhpToken(0,';')];
        $out=$node['head'];$type=$node['head'][0]->id??0;
        foreach ($node['children'] as $i=>$child) {
            $required=($type===T_TRY || in_array($type,[T_CATCH,T_FINALLY,T_ELSE,T_IF,T_ELSEIF,T_FOREACH,T_FOR,T_WHILE],true)) && ($i===0 || $type===T_TRY);
            $out=array_merge($out,self::render($child,$required));
        }
        return array_merge($out,$node['tail']);
    }
    public static function lifecycle(array $tokens,array $sensitive,array $attributes): array
    {
        if (self::opaque($tokens)) return $tokens;
        $open=0;while (($tokens[$open]->text??null)!=='{') if (++$open>=count($tokens)) return $tokens;
        $i=$open+1;$end=count($tokens)-1;
        try { $nodes=self::sequence($tokens,$i,$end); } catch (RuntimeException $e) { return $tokens; }
        $live=[];$persistent=self::persistent($tokens,$attributes)||(bool)array_intersect_key(self::calls($tokens),$sensitive);
        do { $before=count($live);foreach ($nodes as &$node) self::select($node,$live,$sensitive,$attributes,$persistent);unset($node); } while (count($live)!==$before);
        $out=array_slice($tokens,0,$open+1);foreach ($nodes as $node) $out=array_merge($out,self::render($node));$out[]=$tokens[$end];return $out;
    }
    /** Inclusion and trait wiring is resolved separately; only executable bootstrap/declaration tokens are hashed. */
    public static function declaration(array $tokens,bool $class): array
    {
        $out=[];
        for ($i=0,$n=count($tokens);$i<$n;$i++) {
            if (in_array($tokens[$i]->id,$class?[T_USE]:[T_INCLUDE,T_INCLUDE_ONCE,T_REQUIRE,T_REQUIRE_ONCE],true)) {
                if ($class) {
                    $j=$i;while ($j<$n && !in_array($tokens[$j]->text,[';','{'],true)) ++$j;
                    if (($tokens[$j]->text??null)==='{') { $out[]=$tokens[$i];continue; } // Trait adaptations affect method resolution.
                }
                $depth=0;
                for (;$i<$n;$i++) { $s=$tokens[$i]->text;if ($s==='{') ++$depth;elseif ($s==='}' && --$depth===0) break;elseif ($s===';' && $depth===0) break; }
            } else $out[]=$tokens[$i];
        }
        return $out;
    }
}
