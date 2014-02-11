<?php
/* ===========================================================================
 * Opis Project
 * http://opis.io
 * ===========================================================================
 * Copyright (c) 2014 Opis Project
 *
 * Licensed under the MIT License
 * =========================================================================== */

namespace Opis\Closure;

use Closure;
use Serializable;
use SplObjectStorage;

class SerializableClosure implements Serializable
{
    
    protected $closure;
    
    protected $reflector;
    
    protected $code;
    
    protected static $serializations = 0;
    
    protected static $storage;
    
    protected $isBinded = false;
    
    protected $serializeBind = false;
    
     
    public function __construct(Closure $closure, $serializeBind = false)
    {
        $this->serializeBind = (bool) $serializeBind;
        $this->closure = $closure;
    }
    
    protected function &getClosurePointer()
    {
        return $this->closure;
    }
    
    public function getClosure()
    {
        return $this->closure;
    }
    
    public function getReflector()
    {
        if($this->reflector === null)
        {
            $this->reflector = new ReflectionClosure($this->closure, $this->code);
            $this->code = null;
        }
        
        return $this->reflector;
    }
    
    
    protected function &mapPointers(&$value)
    {
        if($value instanceof static)
        {
            $pointer = &$value->getClosurePointer();
            return $pointer;
        }
        elseif(is_array($value))
        {
            $pointer = array_map(array($this, __FUNCTION__), $value);
            return $pointer;
        }
        elseif($value instanceof \stdClass)
        {
            $pointer = (array) $value;
            $pointer = array_map(array($this, __FUNCTION__), $pointer);
            $pointer = (object) $pointer;
            return $pointer;
        }
        return $value;
    }
    
    protected function &mapByReference(&$value)
    {
        if($value instanceof Closure)
        {
            if(isset(static::$storage[$value]))
            {
                $ret = static::$storage[$value];
                return $ret;
            }
            
            $instance = new static($value);
            static::$storage[$value] = $instance;
            return $instance;
        }
        elseif(is_array($value))
        {
            $ret = array_map(array($this, __FUNCTION__), $value);
            return $ret;
        }
        elseif($value instanceof \stdClass)
        {
            $ret = (array) $value;
            $ret = array_map(array($this, __FUNCTION__), $ret);
            $ret = (object) $ret;
            return $ret;
        }
        return $value;
    }
    
    
    public function __invoke()
    {
        //return $this->getReflector()->invokeArgs(func_get_args());
        return $this->isBinded
                    ? call_user_func_array($this->closure, func_get_args())
                    : $this->getReflector()->invokeArgs(func_get_args());
                    
    }
    
    public function serialize()
    {
        
        if (!static::$serializations++)
        {
            static::$storage = new SplObjectStorage();
        }
        
        $reflector = $this->getReflector();
        static::$storage[$this->closure] = $this;
        $variables = $reflector->getStaticVariables();
        $use = &$this->mapByReference($variables);
        
        $scope = null;
        $that = null;
        
        if($this->serializeBind && method_exists($reflector, 'getClosureScopeClass'))
        {
            if($scope = $reflector->getClosureScopeClass())
            {
                $scope = $scope->name;
                $that = $reflector->getClosureThis();
            }
        }
        
        $ret = serialize(array(
            'use' => $use,
            'function' => $reflector->getCode(),
            'scope' => $scope,
            'this' => $that,
        ));
        
        if (!--static::$serializations)
        {
            static::$storage = null;
        }
        
        return $ret;
    }
     
    public function unserialize($data)
    {
        ClosureStream::register();
        
        $this->code = unserialize($data);
        
        $this->code['use'] = array_map(array($this, 'mapPointers'), $this->code['use']);
        
        extract($this->code['use'], EXTR_OVERWRITE | EXTR_REFS);
        
        $this->closure = include(ClosureStream::STREAM_PROTO . '://' . $this->code['function']);
        
        if($this !== $this->code['this'] && ($this->code['scope'] !== null || $this->code['this'] !== null))
        {
            $this->isBinded = $this->serializeBind = true;
            $this->closure = $this->closure->bindTo($this->code['this'], $this->code['scope']);
        }
        
        $this->code = $this->code['function'];
        
    }
 
}
