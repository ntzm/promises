<?php
namespace GuzzleHttp\Promise;

/**
 * Promises/A+ implementation that avoids recursion when possible.
 *
 * In order to benefit from iterative promises, you MUST extend from this
 * promise so that promises can reach into each others' private properties to
 * shift handler ownership.
 *
 * @link https://promisesaplus.com/
 */
class Promise implements PromiseInterface
{
    private $state = self::PENDING;
    private $result;
    private $waitFn;
    private $cancelFn;

    /** @internal Do not rely on this variable in subclasses */
    protected $handlers = [];
    /** @internal Do not rely on this variable in subclasses */
    protected $waitList;

    /**
     * @param callable $waitFn   Fn that when invoked resolves the promise.
     * @param callable $cancelFn Fn that when invoked cancels the promise.
     */
    public function __construct(
        callable $waitFn = null,
        callable $cancelFn = null
    ) {
        $this->waitFn = $waitFn;
        $this->cancelFn = $cancelFn;
    }

    public function then(
        callable $onFulfilled = null,
        callable $onRejected = null
    ) {
        if ($this->state === self::PENDING) {
            return $this->createPendingThen($onFulfilled, $onRejected);
        }

        // Return a fulfilled promise and immediately invoke any callbacks.
        if ($this->state === self::FULFILLED) {
            return $onFulfilled
                ? promise_for($this->result)->then($onFulfilled)
                : promise_for($this->result);
        }

        // It's either cancelled or rejected, so return a rejected promise
        // and immediately invoke any callbacks.
        $rejection = rejection_for($this->result);
        return $onRejected ? $rejection->then(null, $onRejected) : $rejection;
    }

    public function wait($unwrap = true)
    {
        if ($this->state === self::PENDING) {
            if ($this->waitFn) {
                $this->invokeWait();
            } else {
                // If there's not wait function, then reject the promise.
                $this->reject('Cannot wait on a promise that has '
                    . 'no internal wait function. You must provide a wait '
                    . 'function when constructing the promise to be able to '
                    . 'wait on a promise.');
            }
        }

        // If there's no promise forwarding, then return/throw what we have.
        if (!($this->result instanceof PromiseInterface)) {
            if (!$unwrap) {
                return null;
            } elseif ($this->state === self::FULFILLED) {
                return $this->result;
            }
            // It's rejected so "unwrap" and throw an exception.
            throw exception_for($this->result);
        }

        // Wait on nested promises until a normal value is unwrapped/thrown.
        return $this->result->wait($unwrap);
    }

    public function getState()
    {
        return $this->state;
    }

    public function cancel()
    {
        if ($this->state !== self::PENDING) {
            return;
        }

        $this->waitFn = $this->waitList = null;

        if ($this->cancelFn) {
            $fn = $this->cancelFn;
            $this->cancelFn = null;
            try {
                $fn();
            } catch (\Exception $e) {
                $this->reject($e);
                return;
            }
        }

        // Reject the promise only if it wasn't rejected in a then callback.
        if ($this->state === self::PENDING) {
            $this->reject(new CancellationException('Promise has been cancelled'));
        }
    }

    public function resolve($value)
    {
        if ($pending = $this->settle(self::FULFILLED, $value)) {
            $this->resolveStack($pending);
        }
    }

    public function reject($reason)
    {
        if ($pending = $this->settle(self::REJECTED, $reason)) {
            $this->resolveStack($pending);
        }
    }

    private function settle($state, $value)
    {
        if ($this->state !== self::PENDING) {
            throw $this->state === $state
                ? new \RuntimeException("The promise is already {$state}.")
                : new \RuntimeException("Cannot change a {$this->state} promise to {$state}");
        }

        if ($value === $this) {
            throw new \LogicException('Cannot fulfill or reject a promise with itself');
        }

        $this->state = $state;
        $this->result = $value;
        $handlers = $this->handlers;
        $this->handlers = null;
        $this->waitList = null;
        $this->waitFn = null;
        $this->cancelFn = null;

        if (!$handlers) {
            return null;
        }

        return [
            [
                'value'    => $value,
                'index'    => $this->state === self::FULFILLED ? 1 : 2,
                'handlers' => $handlers
            ]
        ];
    }

    /**
     * Resolve a stack of pending groups of handlers.
     *
     * @param array $pending Array of groups of handlers.
     */
    private function resolveStack(array $pending)
    {
        while ($group = array_pop($pending)) {
            // If the resolution value is a promise, then merge the handlers
            // into the promise to be notified when it is fulfilled.
            if ($group['value'] instanceof Promise) {
                if ($nextGroup = $this->resolveForwardPromise($group)) {
                    $pending[] = $nextGroup;
                    unset($nextGroup);
                }
                continue;
            }

            // Thennables that are not of our internal type must be recursively
            // resolved using a then() call.
            if (method_exists($group['value'], 'then')) {
                $this->passToNextPromise($group['value'], $group['handlers']);
                continue;
            }

            // Resolve or reject dependent handlers immediately.
            foreach ($group['handlers'] as $handler) {
                $pending[] = $this->callHandler(
                    $group['index'],
                    $group['value'],
                    $handler
                );
            }
        }
    }

    /**
     * Call a stack of handlers using a specific callback index and value.
     *
     * @param int   $index   1 (resolve) or 2 (reject).
     * @param mixed $value   Value to pass to the callback.
     * @param array $handler Array of handler data (promise and callbacks).
     *
     * @return array Returns the next group to resolve.
     */
    private function callHandler($index, $value, array $handler)
    {
        try {
            // Use the result of the callback if available, otherwise just
            // forward the result down the chain as appropriate.
            if (isset($handler[$index])) {
                $nextValue = $handler[$index]($value);
            } elseif ($index === 1) {
                // Forward resolution values as-is.
                $nextValue = $value;
            } else {
                // Forward rejections down the chain.
                return $this->handleRejection($value, $handler);
            }
        } catch (\Exception $reason) {
            return $this->handleRejection($reason, $handler);
        }

        // You can return a rejected promise to forward a rejection.
        if ($nextValue instanceof PromiseInterface
            && $nextValue->getState() === self::REJECTED
        ) {
            return $this->handleRejection($nextValue, $handler);
        }

        $nextHandlers = $handler[0]->handlers;
        $handler[0]->handlers = null;
        // While waitList is removed in reject(), it needs to be set to null
        // here in order for resources to be garbage collected.
        $handler[0]->waitList = null;
        $handler[0]->resolve($nextValue);

        // Resolve the listeners of this promise
        return [
            'value'    => $nextValue,
            'index'    => 1,
            'handlers' => $nextHandlers
        ];
    }

    /**
     * Reject the listeners of a promise.
     *
     * @param mixed $reason  Rejection resaon.
     * @param array $handler Handler to reject.
     *
     * @return array
     */
    private function handleRejection($reason, array $handler)
    {
        $nextHandlers = $handler[0]->handlers;
        $handler[0]->handlers = null;
        // While waitList is removed in reject(), it needs to be set to null
        // here in order for resources to be garbage collected.
        $handler[0]->waitList = null;
        $handler[0]->reject($reason);

        return [
            'value'    => $reason,
            'index'    => 2,
            'handlers' => $nextHandlers
        ];
    }

    /**
     * The resolve value was a promise, so forward remaining resolution to the
     * resolution of the forwarding promise.
     *
     * @param array $group Group to forward.
     *
     * @return array|null Returns a new group if the promise is delivered or
     *                    returns null if the promise is pending or cancelled.
     */
    private function resolveForwardPromise(array $group)
    {
        /** @var Promise $promise */
        $promise = $group['value'];
        $handlers = $group['handlers'];
        $state = $promise->getState();
        if ($state === self::PENDING) {
            // The promise is an instance of Promise, so merge in the
            // dependent handlers into the promise.
            $promise->handlers = array_merge($promise->handlers, $handlers);
            return null;
        } elseif ($state === self::FULFILLED) {
            return [
                'value'    => $promise->result,
                'handlers' => $handlers,
                'index'    => 1
            ];
        } else { // rejected
            return [
                'value'    => $promise->result,
                'handlers' => $handlers,
                'index'    => 2
            ];
        }
    }

    /**
     * Resolve the dependent handlers recursively when the promise resolves.
     *
     * This function is invoked for thennable objects that are not an instance
     * of Promise (because we have no internal access to the handlers).
     *
     * @param mixed $promise  Promise that is being depended upon.
     * @param array $handlers Dependent handlers.
     */
    private function passToNextPromise($promise, array $handlers)
    {
        $promise->then(
            function ($value) use ($handlers) {
                // resolve the handlers with the given value.
                $stack = [];
                foreach ($handlers as $handler) {
                    $stack[] = $this->callHandler(1, $value, $handler);
                }
                $this->resolveStack($stack);
            }, function ($reason) use ($handlers) {
                // reject the handlers with the given reason.
                $stack = [];
                foreach ($handlers as $handler) {
                    $stack[] = $this->callHandler(2, $reason, $handler);
                }
                $this->resolveStack($stack);
            }
        );
    }

    private function invokeWait()
    {
        $wfn = $this->waitFn;
        $this->waitFn = null;

        try {
            // Invoke the wait fn and ensure it resolves the promise.
            $wfn();
        } catch (\Exception $reason) {
            if ($this->state === self::PENDING) {
                // The promise has not been resolved yet, so reject the promise
                // with the exception.
                $this->reject($reason);
            } else {
                // The promise was already resolved, so there's a problem in
                // the application.
                throw $reason;
            }
        }

        if ($this->state === self::PENDING) {
            $this->reject('Invoking the wait callback did not resolve the promise');
        }
    }

    private function createPendingThen(
        callable $onFulfilled = null,
        callable $onRejected = null
    ) {
        // Instead of waiting with recursion, create a wait function that waits
        // on each waiter in a list one after the other.
        $waitList = $this->waitList ? clone $this->waitList : new \SplQueue();
        $waitList[] = [$this, 'wait'];

        $p = new Promise(static function () use (&$p) {
            if (count($p->waitList)) {
                /** @var callable $wfn */
                foreach ($p->waitList as $wfn) {
                    $wfn(false);
                }
            }
        }, [$this, 'cancel']);
        $p->waitList = $waitList;

        // Keep track of this dependent promise so that we resolve it
        // later when a value has been delivered.
        $this->handlers[] = [$p, $onFulfilled, $onRejected];

        return $p;
    }
}
