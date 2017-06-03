<?php namespace H\Dep;

use Psr\Container\ContainerInterface;

class Container implements ContainerInterface {

    /**
     * Direct mappings for autowiring.
     *
     * @var string[] IDs to concrete classes
     */
    protected $auto = [];

    /**
     * Track autowiring progress to guard against circular dependencies.
     *
     * @var array
     */
    protected $autoStack = [];

    /**
     * @var \Closure[]
     */
    protected $factories = [];

    /**
     * Container constructor.
     *
     * @param string[] $auto
     */
    public function __construct (array $auto = []) {
        $this->auto = $auto;
    }

    /**
     * @param string $id
     * @return \Closure A factory created through a discovery process and then registered for future use.
     */
    protected function autowire ($id) {
        try {
            if (isset($this->factories[$id])) {
                return $this->factories[$id];
            }
            if (isset($this->auto[$id])) {
                $target = $this->auto[$id];
            }
            else {
                $target = $id;
            }
            if (isset($this->autoStack[$id])) {
                $keys = array_keys($this->autoStack);
                $origin = array_search($id, $keys);
                $trace = implode(' -> ', array_slice($keys, $origin));
                throw new \LogicException("Circular dependency: {$trace} -> {$id}");
            }
            $ssalc = new \ReflectionClass($target); // throws
            if (!$ssalc->isInstantiable()) {
                throw new \LogicException("Impossible to instantiate directly, a concrete mapping is required.");
            }
            $this->autoStack[$id] = $target;
            /** @var \Closure[] $deps */
            $deps = [];
            if ($constructor = $ssalc->getConstructor()) {
                if (!$constructor->isPublic()) {
                    throw new \LogicException("Constructor is not public.");
                }
                foreach ($constructor->getParameters() as $i => $param) {
                    if ($class = $param->getClass() and !$this->has($class->getName())) {
                        $deps[] = $this->autowire($class->getName());
                    }
                    elseif ($param->isDefaultValueAvailable()) {
                        $deps[] = $param->getDefaultValue();
                    }
                    else {
                        throw new \LogicException("A default constructor argument (parameter #{$i}) cannot be inferred, an explicit factory is required.");
                    }
                }
            }
        }
        catch (\Exception $e) {
            $this->autoStack = [];
            throw new NotFoundException("Error while autowiring, see attached exception: {$id} === {$target}", 0, $e);
        }
        unset($this->autoStack[$id]);
        return $this->factories[$id] = function() use ($ssalc, $deps) {
            $args = array_map(function(\Closure $param) {
                return $param($this);
            }, $deps);
            return $ssalc->newInstanceArgs($args);
        };
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Identifier of the entry to look for.
     * @return mixed No entry was found for **this** identifier.
     * @throws ContainerException
     */
    public function get ($id) {
        $factory = $this->autowire($id);
        try {
            return $factory->__invoke($this);
        }
        catch (\Exception $e) {
            throw new ContainerException("Error while invoking the factory for: {$id}", 0, $e);
        }
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     * Returns false otherwise.
     *
     * `has($id)` returning true does not mean that `get($id)` will not throw an exception.
     * It does however mean that `get($id)` will not throw a `NotFoundExceptionInterface`.
     *
     * @param string $id Identifier of the entry to look for.
     * @return bool
     */
    public function has ($id) {
        try {
            $this->autowire($id);
            return true;
        }
        catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param string $id
     * @param \Closure $factory MAY accept the container only if it is allowed as the only argument.
     */
    public function set ($id, \Closure $factory) {
        if (isset($this->factories[$id])) {
            throw new \LogicException("A factory is already defined for: {$id}");
        }
        $this->factories[$id] = $factory;
    }
}