<?php

/**
 * \AppserverIo\Appserver\Naming\NamingDirectory
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * PHP version 5
 *
 * @author    Tim Wagner <tw@appserver.io>
 * @copyright 2015 TechDivision GmbH <info@appserver.io>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/appserver-io/appserver
 * @link      http://www.appserver.io
 */

namespace AppserverIo\Appserver\Naming;

use Rhumsaa\Uuid\Uuid;
use AppserverIo\Storage\GenericStackable;
use AppserverIo\Psr\Naming\NamingException;
use AppserverIo\Psr\Naming\NamingDirectoryInterface;
use AppserverIo\Appserver\Core\Traits\ThreadedContextTrait;

/**
 * Naming directory implementation.
 *
 * @author    Tim Wagner <tw@appserver.io>
 * @copyright 2015 TechDivision GmbH <info@appserver.io>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/appserver-io/appserver
 * @link      http://www.appserver.io
 *
 * @property \AppserverIo\Psr\Naming\NamingDirectoryInterface $parent The parent directory
 * @property string                                           $scheme The binding string scheme
 * @property string                                           $name   The directory name
 * @property string                                           $serial The instance unique serial number
 */
class NamingDirectory extends GenericStackable implements NamingDirectoryInterface
{

    /**
     * Trait that provides threaded context functionality.
     *
     * @var AppserverIo\Appserver\Core\Traits\ThreadedContextTrait
     */
    use ThreadedContextTrait;

    /**
     * Initialize the directory with a name and the parent one.
     *
     * @param string                                           $name   The directory name
     * @param \AppserverIo\Psr\Naming\NamingDirectoryInterface $parent The parent directory
     */
    public function __construct($name = null, NamingDirectoryInterface $parent = null)
    {

        // initialize the members
        $this->parent = $parent;
        $this->name = $name;

        // create a UUID as prefix for dynamic object properties
        $this->serial = Uuid::uuid4()->toString();
    }

    /**
     * Returns the directory name.
     *
     * @return string The directory name
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Returns the parend directory.
     *
     * @return \AppserverIo\Psr\Naming\NamingDirectoryInterface
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Set the scheme, php or http for example
     *
     * @param string $scheme The scheme we want to use
     *
     * @return void
     */
    public function setScheme($scheme)
    {
        $this->scheme = $scheme;
    }

    /**
     * Returns the scheme.
     *
     * @return string The scheme we want to use
     */
    public function getScheme()
    {

        // if the parent directory has a schema, return this one
        if ($parent = $this->getParent()) {
            return $parent->getScheme();
        }

        // return our own schema
        return $this->scheme;
    }

    /**
     * Binds the passed instance with the name to the naming directory.
     *
     * @param string $name  The name to bind the value with
     * @param mixed  $value The object instance to bind
     * @param array  $args  The array with the arguments
     *
     * @return void
     * @throws \AppserverIo\Psr\Naming\NamingException Is thrown if the value can't be bound ot the directory
     */
    public function bind($name, $value, array $args = array())
    {

        // delegate the bind request to the parent directory
        if (strpos($name, sprintf('%s:', $this->getScheme())) === 0 && $this->getParent()) {
            return $this->findRoot()->bind($name, $value, $args);
        }

        // strip off the schema
        $name = str_replace(sprintf('%s:', $this->getScheme()), '', $name);

        // tokenize the name
        $token = strtok($name, '/');

        // while we've tokens, try to find the appropriate subdirectory
        while ($token !== false) {
            // check if we can find something
            if ($this->hasAttribute($token)) {
                // load the data bound to the token
                $data = $this->getAttribute($token);

                // load the bound value/args
                list ($valueFound, ) = $data;

                // try to bind it to the subdirectory
                if ($valueFound instanceof NamingDirectoryInterface) {
                    return $valueFound->bind(str_replace($token . '/', '', $name), $value, $args);
                }

                // throw an exception if we can't resolve the name
                throw new NamingException(sprintf('Cant\'t bind %s to value of naming directory %s', $token, $this->getIdentifier()));

            } else {
                // bind the value
                return $this->setAttribute($token, array($value, $args));
            }

            // load the next token
            $token = strtok('/');
        }

        // throw an exception if we can't resolve the name
        throw new NamingException(sprintf('Cant\'t bind %s to naming directory %s', $token, $this->getIdentifier()));
    }

    /**
     * Binds the passed callback with the name to the naming directory.
     *
     * @param string   $name     The name to bind the callback with
     * @param callable $callback The callback to be invoked when searching for
     * @param array    $args     The array with the arguments passed to the callback when executed
     *
     * @return void
     * @see \AppserverIo\Appserver\Naming\NamingDirectory::bind()
     */
    public function bindCallback($name, callable $callback, array $args = array())
    {
        $this->bind($name, $callback, $args);
    }

    /**
     * Binds a reference with the passed name to the naming directory.
     *
     * @param string $name      The name to bind the reference with
     * @param string $reference The name of the reference
     *
     * @return void
     * @see \AppserverIo\Appserver\Naming\NamingDirectory::bind()
     */
    public function bindReference($name, $reference)
    {
        $this->bindCallback($name, array(&$this, 'search'), array($reference, array()));
    }

    /**
     * Unbinds the named object from the naming directory.
     *
     * @param string $name The name of the object to unbind
     *
     * @return void
     */
    public function unbind($name)
    {

        // delegate the bind request to the parent directory
        if (strpos($name, sprintf('%s:', $this->getScheme())) === 0 && $this->getParent()) {
            return $this->findRoot()->unbind($name);
        }

        // strip off the schema
        $name = str_replace(sprintf('%s:', $this->getScheme()), '', $name);

        // tokenize the name
        $token = strtok($name, '/');

        // while we've tokens, try to find the appropriate subdirectory
        while ($token !== false) {
            // check if we can find something
            if ($this->hasAttribute($token)) {
                // load the data bound to the token
                $data = $this->getAttribute($token);

                // load the bound value/args
                list ($valueFound, ) = $data;

                // try to unbind it from the subdirectory
                if ($valueFound instanceof NamingDirectoryInterface) {
                    if ($valueFound->getName() !== $name) {
                        return $valueFound->unbind(str_replace($token . '/', '', $name));
                    }
                }

                // remove the attribute if we find the requested value
                return $this->removeAttribute($token);
            }

            // load the next token
            $token = strtok('/');
        }

        // throw an exception if we can't resolve the name
        throw new NamingException(sprintf('Cant\'t unbind %s from naming directory %s', $name, $this->getIdentifier()));
    }

    /**
     * Queries the naming directory for the requested name and returns the value
     * or invokes the bound callback.
     *
     * @param string $name The name of the requested value
     * @param array  $args The arguments to pass to the callback
     *
     * @return mixed The requested value
     * @throws \AppserverIo\Psr\Naming\NamingException Is thrown if the requested name can't be resolved in the directory
     */
    public function search($name, array $args = array())
    {

        // delegate the search request to the parent directory
        if (strpos($name, sprintf('%s:', $this->getScheme())) === 0 && $this->getParent()) {
            return $this->findRoot()->search($name, $args);
        }

        // strip off the schema
        $name = str_replace(sprintf('%s:', $this->getScheme()), '', $name);

        // tokenize the name
        $token = strtok($name, '/');

        // while we've tokens, try to find a value bound to the token
        while ($token !== false) {
            // check if we can find something
            if ($this->hasAttribute($token)) {
                // load the value
                $found = $this->getAttribute($token);

                // load the binded value/args
                list ($value, $bindArgs) = $found;

                // check if we've a callback method
                if (is_callable($value)) {
                    // if yes, merge the params and invoke the callback
                    foreach ($args as $arg) {
                        $bindArgs[] = $arg;
                    }

                    // invoke the callback
                    return call_user_func_array($value, $bindArgs);
                }

                // search recursive
                if ($value instanceof NamingDirectoryInterface) {
                    if ($value->getName() !== $name) {
                        // if $value is NOT what we're searching for
                        return $value->search(str_replace($token . '/', '', $name), $args);
                    }
                }

                // if not, simply return the value/object
                return $value;
            }

            // load the next token
            $token = strtok('/');
        }

        // throw an exception if we can't resolve the name
        throw new NamingException(sprintf('Cant\'t resolve %s in naming directory %s', ltrim($name, '/'), $this->getIdentifier()));
    }

    /**
     * The unique identifier of this directory. That'll be build up
     * recursive from the scheme and the root directory.
     *
     * @return string The unique identifier
     * @see \AppserverIo\Storage\StorageInterface::getIdentifier()
     *
     * @throws \AppserverIo\Psr\Naming\NamingException
     */
    public function getIdentifier()
    {

        // check if we've a parent directory
        if ($parent = $this->getParent()) {
            return $parent->getIdentifier() . $this->getName() . '/';
        }


        if ($scheme = $this->getScheme()) {
            return $scheme . ':' . $this->getName();
        }

        // the root node needs a scheme
        throw new NamingException(sprintf('Missing scheme for naming directory', $this->getName()));
    }

    /**
     * Returns the root node of the naming directory tree.
     *
     * @return \AppserverIo\Psr\Naming\NamingDirectoryInterface The root node
     */
    public function findRoot()
    {

        // query whether we've a parent or not
        if ($parent = $this->getParent()) {
            return $parent->findRoot();
        }

        // return the node itself if we're root
        return $this;
    }

    /**
     * Builds an array with a string representation of the naming
     * directories content.
     *
     * @param array $buffer The array to append the values to
     *
     * @return array The array with the naming directories string representation
     */
    public function toArray(array &$buffer = array())
    {

        // query whether we've attributes or not
        if ($attributes = $this->getAttributes()) {
            // iterate over the attributes
            foreach ($attributes as $key => $found) {
                // extract the binded value/args if necessary
                if (is_array($found)) {
                    list ($value, ) = $found;
                } else {
                    $value = $found;
                }

                // initialize the strings for node value and type
                $val = 'n/a';
                $type = 'unknown';

                // set value and type strings based on the found value
                if (is_null($value)) {
                    $val = 'NULL';
                } elseif (is_object($value)) {
                    $type = get_class($value);
                } elseif (is_callable($value)) {
                    $type = 'callback';
                } elseif (is_array($value)) {
                    $type = 'array';
                } elseif (is_scalar($value)) {
                    $type = gettype($value);
                    $val = $value;
                } elseif (is_resource($value)) {
                    $type = 'resource';
                }

                // append type and value string representations to the buffer
                $buffer[sprintf('%s%s', $this->getIdentifier(), $this->unmaskKey($key))] = sprintf('(%s) %s', $type, $val);

                // if the value is a naming directory also, append it recursive
                if ($value instanceof NamingDirectoryInterface) {
                    $value->toArray($buffer);
                }
            }
        }

        // return the buffer
        return $buffer;
    }

    /**
     * Returns a string representation of the naming directory
     *
     * @return string The string representation of the naming directory
     */
    public function __toString()
    {
        return implode(PHP_EOL, $this->toArray());
    }

    /**
     * Create and return a new naming subdirectory with the attributes
     * of this one.
     *
     * @param string $name   The name of the new subdirectory
     * @param array  $filter Array with filters that will be applied when copy the attributes
     *
     * @return \AppserverIo\Appserver\Naming\NamingDirectory The new naming subdirectory
     */
    public function createSubdirectory($name, array $filter = array())
    {

        try {
            // cut off append slashes
            $name = rtrim($name, '/');

            // query whether we found a slash AND a prepended scheme
            if (strpos($name, sprintf('%s:', $this->getScheme())) === 0 && ($found = strrpos($name, '/')) !== false) {
                // cut off the last directory
                $parentDirectory = substr($name, 0, $found);

                // prepare the name of the subdirectory to create
                $newDirectory = ltrim(str_replace($parentDirectory, '', $name), '/');

                // load the parent directory and create the new subdirectory
                return $this->search($parentDirectory)->createSubdirectory($newDirectory, $filter);
            }

            // strip off the schema
            $name = str_replace(sprintf('%s:', $this->getScheme()), '', $name);

            // create a local copy of the naming directory stack
            global $directories;

            // create a new subdirectory instance
            $directories[$id = Uuid::uuid4()->__toString()] = new NamingDirectory($name, $this);

            // copy the attributes specified by the filter
            if (sizeof($filter) > 0) {
                foreach ($this->getAllKeys() as $key => $value) {
                    foreach ($filter as $pattern) {
                        if (fnmatch($pattern, $key)) {
                            $directories[$id]->bind($key, $value);
                        }
                    }
                }
            }

            // bind it the directory
            $this->bind($name, $directories[$id]);

            // return the instance
            return $directories[$id];

        } catch (\Exception $e) {
            error_log($e->__toString());
        }
    }
}
