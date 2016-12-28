<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Loader;

use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Util\XmlUtils;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Argument\ClosureProxyArgument;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\ExpressionLanguage\Expression;

/**
 * XmlFileLoader loads XML files service definitions.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class XmlFileLoader extends FileLoader
{
    const NS = 'http://symfony.com/schema/dic/services';

    /**
     * {@inheritdoc}
     */
    public function load($resource, $type = null)
    {
        $path = $this->locator->locate($resource);

        $xml = $this->parseFileToDOM($path);

        $this->container->addResource(new FileResource($path));

        // anonymous services
        $this->processAnonymousServices($xml, $path);

        // imports
        $this->parseImports($xml, $path);

        // parameters
        $this->parseParameters($xml);

        // extensions
        $this->loadFromExtensions($xml);

        // services
        $this->parseDefinitions($xml, $path);
    }

    /**
     * {@inheritdoc}
     */
    public function supports($resource, $type = null)
    {
        return is_string($resource) && 'xml' === pathinfo($resource, PATHINFO_EXTENSION);
    }

    /**
     * Parses parameters.
     *
     * @param \DOMDocument $xml
     */
    private function parseParameters(\DOMDocument $xml)
    {
        if ($parameters = $this->getChildren($xml->documentElement, 'parameters')) {
            $this->container->getParameterBag()->add($this->getArgumentsAsPhp($parameters[0], 'parameter'));
        }
    }

    /**
     * Parses imports.
     *
     * @param \DOMDocument $xml
     * @param string       $file
     */
    private function parseImports(\DOMDocument $xml, $file)
    {
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('container', self::NS);

        if (false === $imports = $xpath->query('//container:imports/container:import')) {
            return;
        }

        $defaultDirectory = dirname($file);
        foreach ($imports as $import) {
            $this->setCurrentDir($defaultDirectory);
            $this->import($import->getAttribute('resource'), null, (bool) XmlUtils::phpize($import->getAttribute('ignore-errors')), $file);
        }
    }

    /**
     * Parses multiple definitions.
     *
     * @param \DOMDocument $xml
     * @param string       $file
     */
    private function parseDefinitions(\DOMDocument $xml, $file)
    {
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('container', self::NS);

        if (false === $services = $xpath->query('//container:services/container:service')) {
            return;
        }

        foreach ($services as $service) {
            if (null !== $definition = $this->parseDefinition($service, $file, $this->getServiceDefaults($xml, $file))) {
                $this->container->setDefinition((string) $service->getAttribute('id'), $definition);
            }
        }
    }

    /**
     * Get service defaults.
     *
     * @return array
     */
    private function getServiceDefaults(\DOMDocument $xml, $file)
    {
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('container', self::NS);

        if (null === $defaultsNode = $xpath->query('//container:services/container:defaults')->item(0)) {
            return array();
        }
        $defaults = array(
            'tags' => $this->getChildren($defaultsNode, 'tag'),
            'autowire' => $this->getChildren($defaultsNode, 'autowire'),
        );

        foreach ($defaults['tags'] as $tag) {
            if ('' === $tag->getAttribute('name')) {
                throw new InvalidArgumentException(sprintf('The tag name for tag "<defaults>" in %s must be a non-empty string.', $file));
            }
        }
        if ($defaultsNode->hasAttribute('public')) {
            $defaults['public'] = XmlUtils::phpize($defaultsNode->getAttribute('public'));
        }
        if (!$defaultsNode->hasAttribute('autowire')) {
            foreach ($defaults['autowire'] as $k => $v) {
                $defaults['autowire'][$k] = $v->textContent;
            }

            return $defaults;
        }
        if ($defaults['autowire']) {
            throw new InvalidArgumentException(sprintf('The "autowire" attribute cannot be used together with "<autowire>" tags for tag "<defaults>" in %s.', $file));
        }
        if (XmlUtils::phpize($defaultsNode->getAttribute('autowire'))) {
            $defaults['autowire'][] = '__construct';
        }

        return $defaults;
    }

    /**
     * Parses an individual Definition.
     *
     * @param \DOMElement $service
     * @param string      $file
     * @param array       $defaults
     *
     * @return Definition|null
     */
    private function parseDefinition(\DOMElement $service, $file, array $defaults = array())
    {
        if ($alias = $service->getAttribute('alias')) {
            $this->validateAlias($service, $file);

            $public = true;
            if ($publicAttr = $service->getAttribute('public')) {
                $public = XmlUtils::phpize($publicAttr);
            } elseif (isset($defaults['public'])) {
                $public = $defaults['public'];
            }
            $this->container->setAlias((string) $service->getAttribute('id'), new Alias($alias, $public));

            return;
        }

        if ($parent = $service->getAttribute('parent')) {
            $definition = new ChildDefinition($parent);
            $defaults = array();
        } else {
            $definition = new Definition();
        }

        if ($publicAttr = $service->getAttribute('public')) {
            $definition->setPublic(XmlUtils::phpize($publicAttr));
        } elseif (isset($defaults['public'])) {
            $definition->setPublic($defaults['public']);
        }

        foreach (array('class', 'shared', 'synthetic', 'lazy', 'abstract') as $key) {
            if ($value = $service->getAttribute($key)) {
                $method = 'set'.$key;
                $definition->$method(XmlUtils::phpize($value));
            }
        }

        if ($value = $service->getAttribute('autowire')) {
            $definition->setAutowired(XmlUtils::phpize($value));
        }

        if ($files = $this->getChildren($service, 'file')) {
            $definition->setFile($files[0]->nodeValue);
        }

        if ($deprecated = $this->getChildren($service, 'deprecated')) {
            $definition->setDeprecated(true, $deprecated[0]->nodeValue ?: null);
        }

        $definition->setArguments($this->getArgumentsAsPhp($service, 'argument'));
        $definition->setProperties($this->getArgumentsAsPhp($service, 'property'));

        if ($factories = $this->getChildren($service, 'factory')) {
            $factory = $factories[0];
            if ($function = $factory->getAttribute('function')) {
                $definition->setFactory($function);
            } else {
                $factoryService = $this->getChildren($factory, 'service');

                if (isset($factoryService[0])) {
                    $class = $this->parseDefinition($factoryService[0], $file);
                } elseif ($childService = $factory->getAttribute('service')) {
                    $class = new Reference($childService, ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, false);
                } else {
                    $class = $factory->getAttribute('class');
                }

                $definition->setFactory(array($class, $factory->getAttribute('method')));
            }
        }

        if ($configurators = $this->getChildren($service, 'configurator')) {
            $configurator = $configurators[0];
            if ($function = $configurator->getAttribute('function')) {
                $definition->setConfigurator($function);
            } else {
                $configuratorService = $this->getChildren($configurator, 'service');

                if (isset($configuratorService[0])) {
                    $class = $this->parseDefinition($configuratorService[0], $file);
                } elseif ($childService = $configurator->getAttribute('service')) {
                    $class = new Reference($childService, ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, false);
                } else {
                    $class = $configurator->getAttribute('class');
                }

                $definition->setConfigurator(array($class, $configurator->getAttribute('method')));
            }
        }

        foreach ($this->getChildren($service, 'call') as $call) {
            $definition->addMethodCall($call->getAttribute('method'), $this->getArgumentsAsPhp($call, 'argument'));
        }

        $tags = $this->getChildren($service, 'tag');
        if (!$tags && !empty($defaults['tags'])) {
            $tags = $defaults['tags'];
        }

        foreach ($tags as $tag) {
            $parameters = array();
            foreach ($tag->attributes as $name => $node) {
                if ('name' === $name) {
                    continue;
                }

                if (false !== strpos($name, '-') && false === strpos($name, '_') && !array_key_exists($normalizedName = str_replace('-', '_', $name), $parameters)) {
                    $parameters[$normalizedName] = XmlUtils::phpize($node->nodeValue);
                }
                // keep not normalized key
                $parameters[$name] = XmlUtils::phpize($node->nodeValue);
            }

            if ('' === $tag->getAttribute('name')) {
                throw new InvalidArgumentException(sprintf('The tag name for service "%s" in %s must be a non-empty string.', (string) $service->getAttribute('id'), $file));
            }

            $definition->addTag($tag->getAttribute('name'), $parameters);
        }

        foreach ($this->getChildren($service, 'autowiring-type') as $type) {
            $definition->addAutowiringType($type->textContent);
        }

        $autowireTags = array();
        foreach ($this->getChildren($service, 'autowire') as $type) {
            $autowireTags[] = $type->textContent;
        }

        if ($autowireTags) {
            if ($service->hasAttribute('autowire')) {
                throw new InvalidArgumentException(sprintf('The "autowire" attribute cannot be used together with "<autowire>" tags for service "%s" in %s.', (string) $service->getAttribute('id'), $file));
            }

            $definition->setAutowiredMethods($autowireTags);
        } elseif (!$service->hasAttribute('autowire') && !empty($defaults['autowire'])) {
            $definition->setAutowiredMethods($defaults['autowire']);
        }

        if ($value = $service->getAttribute('decorates')) {
            $renameId = $service->hasAttribute('decoration-inner-name') ? $service->getAttribute('decoration-inner-name') : null;
            $priority = $service->hasAttribute('decoration-priority') ? $service->getAttribute('decoration-priority') : 0;
            $definition->setDecoratedService($value, $renameId, $priority);
        }

        return $definition;
    }

    /**
     * Parses a XML file to a \DOMDocument.
     *
     * @param string $file Path to a file
     *
     * @return \DOMDocument
     *
     * @throws InvalidArgumentException When loading of XML file returns error
     */
    private function parseFileToDOM($file)
    {
        try {
            $dom = XmlUtils::loadFile($file, array($this, 'validateSchema'));
        } catch (\InvalidArgumentException $e) {
            throw new InvalidArgumentException(sprintf('Unable to parse file "%s".', $file), $e->getCode(), $e);
        }

        $this->validateExtensions($dom, $file);

        return $dom;
    }

    /**
     * Processes anonymous services.
     *
     * @param \DOMDocument $xml
     * @param string       $file
     */
    private function processAnonymousServices(\DOMDocument $xml, $file)
    {
        $definitions = array();
        $count = 0;

        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('container', self::NS);

        // anonymous services as arguments/properties
        if (false !== $nodes = $xpath->query('//container:argument[@type="service"][not(@id)]|//container:property[@type="service"][not(@id)]')) {
            foreach ($nodes as $node) {
                // give it a unique name
                $id = sprintf('%d_%s', ++$count, hash('sha256', $file));
                $node->setAttribute('id', $id);

                if ($services = $this->getChildren($node, 'service')) {
                    $definitions[$id] = array($services[0], $file, false);
                    $services[0]->setAttribute('id', $id);

                    // anonymous services are always private
                    // we could not use the constant false here, because of XML parsing
                    $services[0]->setAttribute('public', 'false');
                }
            }
        }

        // anonymous services "in the wild"
        if (false !== $nodes = $xpath->query('//container:services/container:service[not(@id)]')) {
            foreach ($nodes as $node) {
                // give it a unique name
                $id = sprintf('%d_%s', ++$count, hash('sha256', $file));
                $node->setAttribute('id', $id);
                $definitions[$id] = array($node, $file, true);
            }
        }

        // resolve definitions
        uksort($definitions, 'strnatcmp');
        foreach (array_reverse($definitions) as $id => list($domElement, $file, $wild)) {
            if (null !== $definition = $this->parseDefinition($domElement, $file)) {
                $this->container->setDefinition($id, $definition);
            }

            if (true === $wild) {
                $tmpDomElement = new \DOMElement('_services', null, self::NS);
                $domElement->parentNode->replaceChild($tmpDomElement, $domElement);
                $tmpDomElement->setAttribute('id', $id);
            } else {
                $domElement->parentNode->removeChild($domElement);
            }
        }
    }

    /**
     * Returns arguments as valid php types.
     *
     * @param \DOMElement $node
     * @param string      $name
     * @param bool        $lowercase
     *
     * @return mixed
     */
    private function getArgumentsAsPhp(\DOMElement $node, $name, $lowercase = true)
    {
        $arguments = array();
        foreach ($this->getChildren($node, $name) as $arg) {
            if ($arg->hasAttribute('name')) {
                $arg->setAttribute('key', $arg->getAttribute('name'));
            }

            // this is used by ChildDefinition to overwrite a specific
            // argument of the parent definition
            if ($arg->hasAttribute('index')) {
                $key = 'index_'.$arg->getAttribute('index');
            } elseif (!$arg->hasAttribute('key')) {
                // Append an empty argument, then fetch its key to overwrite it later
                $arguments[] = null;
                $keys = array_keys($arguments);
                $key = array_pop($keys);
            } else {
                $key = $arg->getAttribute('key');

                // parameter keys are case insensitive
                if ('parameter' == $name && $lowercase) {
                    $key = strtolower($key);
                }
            }

            $onInvalid = $arg->getAttribute('on-invalid');
            $invalidBehavior = ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE;
            if ('ignore' == $onInvalid) {
                $invalidBehavior = ContainerInterface::IGNORE_ON_INVALID_REFERENCE;
            } elseif ('null' == $onInvalid) {
                $invalidBehavior = ContainerInterface::NULL_ON_INVALID_REFERENCE;
            }

            switch ($arg->getAttribute('type')) {
                case 'service':
                    $arguments[$key] = new Reference($arg->getAttribute('id'), $invalidBehavior);
                    break;
                case 'expression':
                    $arguments[$key] = new Expression($arg->nodeValue);
                    break;
                case 'closure-proxy':
                    $arguments[$key] = new ClosureProxyArgument($arg->getAttribute('id'), $arg->getAttribute('method'), $invalidBehavior);
                    break;
                case 'collection':
                    $arguments[$key] = $this->getArgumentsAsPhp($arg, $name, false);
                    break;
                case 'iterator':
                    $arguments[$key] = new IteratorArgument($this->getArgumentsAsPhp($arg, $name, false));
                    break;
                case 'string':
                    $arguments[$key] = $arg->nodeValue;
                    break;
                case 'constant':
                    $arguments[$key] = constant(trim($arg->nodeValue));
                    break;
                default:
                    $arguments[$key] = XmlUtils::phpize($arg->nodeValue);
            }
        }

        return $arguments;
    }

    /**
     * Get child elements by name.
     *
     * @param \DOMNode $node
     * @param mixed    $name
     *
     * @return array
     */
    private function getChildren(\DOMNode $node, $name)
    {
        $children = array();
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $name && $child->namespaceURI === self::NS) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * Validates a documents XML schema.
     *
     * @param \DOMDocument $dom
     *
     * @return bool
     *
     * @throws RuntimeException When extension references a non-existent XSD file
     */
    public function validateSchema(\DOMDocument $dom)
    {
        $schemaLocations = array('http://symfony.com/schema/dic/services' => str_replace('\\', '/', __DIR__.'/schema/dic/services/services-1.0.xsd'));

        if ($element = $dom->documentElement->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'schemaLocation')) {
            $items = preg_split('/\s+/', $element);
            for ($i = 0, $nb = count($items); $i < $nb; $i += 2) {
                if (!$this->container->hasExtension($items[$i])) {
                    continue;
                }

                if (($extension = $this->container->getExtension($items[$i])) && false !== $extension->getXsdValidationBasePath()) {
                    $path = str_replace($extension->getNamespace(), str_replace('\\', '/', $extension->getXsdValidationBasePath()).'/', $items[$i + 1]);

                    if (!is_file($path)) {
                        throw new RuntimeException(sprintf('Extension "%s" references a non-existent XSD file "%s"', get_class($extension), $path));
                    }

                    $schemaLocations[$items[$i]] = $path;
                }
            }
        }

        $tmpfiles = array();
        $imports = '';
        foreach ($schemaLocations as $namespace => $location) {
            $parts = explode('/', $location);
            if (0 === stripos($location, 'phar://')) {
                $tmpfile = tempnam(sys_get_temp_dir(), 'sf2');
                if ($tmpfile) {
                    copy($location, $tmpfile);
                    $tmpfiles[] = $tmpfile;
                    $parts = explode('/', str_replace('\\', '/', $tmpfile));
                }
            }
            $drive = '\\' === DIRECTORY_SEPARATOR ? array_shift($parts).'/' : '';
            $location = 'file:///'.$drive.implode('/', array_map('rawurlencode', $parts));

            $imports .= sprintf('  <xsd:import namespace="%s" schemaLocation="%s" />'."\n", $namespace, $location);
        }

        $source = <<<EOF
<?xml version="1.0" encoding="utf-8" ?>
<xsd:schema xmlns="http://symfony.com/schema"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    targetNamespace="http://symfony.com/schema"
    elementFormDefault="qualified">

    <xsd:import namespace="http://www.w3.org/XML/1998/namespace"/>
$imports
</xsd:schema>
EOF
        ;

        $disableEntities = libxml_disable_entity_loader(false);
        $valid = @$dom->schemaValidateSource($source);
        libxml_disable_entity_loader($disableEntities);

        foreach ($tmpfiles as $tmpfile) {
            @unlink($tmpfile);
        }

        return $valid;
    }

    /**
     * Validates an alias.
     *
     * @param \DOMElement $alias
     * @param string      $file
     */
    private function validateAlias(\DOMElement $alias, $file)
    {
        foreach ($alias->attributes as $name => $node) {
            if (!in_array($name, array('alias', 'id', 'public'))) {
                @trigger_error(sprintf('Using the attribute "%s" is deprecated for the service "%s" which is defined as an alias in "%s". Allowed attributes for service aliases are "alias", "id" and "public". The XmlFileLoader will raise an exception in Symfony 4.0, instead of silently ignoring unsupported attributes.', $name, $alias->getAttribute('id'), $file), E_USER_DEPRECATED);
            }
        }

        foreach ($alias->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->namespaceURI === self::NS) {
                @trigger_error(sprintf('Using the element "%s" is deprecated for the service "%s" which is defined as an alias in "%s". The XmlFileLoader will raise an exception in Symfony 4.0, instead of silently ignoring unsupported elements.', $child->localName, $alias->getAttribute('id'), $file), E_USER_DEPRECATED);
            }
        }
    }

    /**
     * Validates an extension.
     *
     * @param \DOMDocument $dom
     * @param string       $file
     *
     * @throws InvalidArgumentException When no extension is found corresponding to a tag
     */
    private function validateExtensions(\DOMDocument $dom, $file)
    {
        foreach ($dom->documentElement->childNodes as $node) {
            if (!$node instanceof \DOMElement || 'http://symfony.com/schema/dic/services' === $node->namespaceURI) {
                continue;
            }

            // can it be handled by an extension?
            if (!$this->container->hasExtension($node->namespaceURI)) {
                $extensionNamespaces = array_filter(array_map(function ($ext) { return $ext->getNamespace(); }, $this->container->getExtensions()));
                throw new InvalidArgumentException(sprintf(
                    'There is no extension able to load the configuration for "%s" (in %s). Looked for namespace "%s", found %s',
                    $node->tagName,
                    $file,
                    $node->namespaceURI,
                    $extensionNamespaces ? sprintf('"%s"', implode('", "', $extensionNamespaces)) : 'none'
                ));
            }
        }
    }

    /**
     * Loads from an extension.
     *
     * @param \DOMDocument $xml
     */
    private function loadFromExtensions(\DOMDocument $xml)
    {
        foreach ($xml->documentElement->childNodes as $node) {
            if (!$node instanceof \DOMElement || $node->namespaceURI === self::NS) {
                continue;
            }

            $values = static::convertDomElementToArray($node);
            if (!is_array($values)) {
                $values = array();
            }

            $this->container->loadFromExtension($node->namespaceURI, $values);
        }
    }

    /**
     * Converts a \DomElement object to a PHP array.
     *
     * The following rules applies during the conversion:
     *
     *  * Each tag is converted to a key value or an array
     *    if there is more than one "value"
     *
     *  * The content of a tag is set under a "value" key (<foo>bar</foo>)
     *    if the tag also has some nested tags
     *
     *  * The attributes are converted to keys (<foo foo="bar"/>)
     *
     *  * The nested-tags are converted to keys (<foo><foo>bar</foo></foo>)
     *
     * @param \DomElement $element A \DomElement instance
     *
     * @return array A PHP array
     */
    public static function convertDomElementToArray(\DOMElement $element)
    {
        return XmlUtils::convertDomElementToArray($element);
    }
}
