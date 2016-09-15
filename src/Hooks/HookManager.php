<?php
namespace Consolidation\AnnotatedCommand\Hooks;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use Consolidation\AnnotatedCommand\ExitCodeInterface;
use Consolidation\AnnotatedCommand\OutputDataInterface;
use Consolidation\AnnotatedCommand\AnnotationData;

/**
 * Manage named callback hooks
 */
class HookManager implements EventSubscriberInterface
{
    protected $hooks = [];

    const PRE_INTERACT = 'pre-interact';
    const INTERACT = 'interact';
    const POST_INTERACT = 'post-interact';
    const PRE_ARGUMENT_VALIDATOR = 'pre-validate';
    const ARGUMENT_VALIDATOR = 'validate';
    const POST_ARGUMENT_VALIDATOR = 'post-validate';
    const PRE_COMMAND_EVENT = 'pre-command';
    const COMMAND_EVENT = 'command';
    const POST_COMMAND_EVENT = 'post-command';
    const PRE_PROCESS_RESULT = 'pre-process';
    const PROCESS_RESULT = 'process';
    const POST_PROCESS_RESULT = 'post-process';
    const PRE_ALTER_RESULT = 'pre-alter';
    const ALTER_RESULT = 'alter';
    const POST_ALTER_RESULT = 'post-alter';
    const STATUS_DETERMINER = 'status';
    const EXTRACT_OUTPUT = 'extract';

    public function __construct()
    {
    }

    public function getAllHooks()
    {
        return $this->hooks;
    }

    /**
     * Add a hook
     *
     * @param mixed $callback The callback function to call
     * @param string   $hook     The name of the hook to add
     * @param string   $name     The name of the command to hook
     *   ('*' for all)
     */
    public function add(callable $callback, $hook, $name = '*')
    {
        if (empty($name)) {
            $name = static::getClassNameFromCallback($callback);
        }
        $this->hooks[$name][$hook][] = $callback;
    }

    /**
     * If a command hook does not specify any particular command
     * name that it should be attached to, then it will be applied
     * to every command that is defined in the same class as the hook.
     * This is controlled by using the namespace + class name of
     * the implementing class of the callback hook.
     */
    public static function getClassNameFromCallback($callback)
    {
        if (!is_array($callback)) {
            return '';
        }
        $reflectionClass = new \ReflectionClass($callback[0]);
        return $reflectionClass->getName();
    }

    /**
     * Add an interact hook
     *
     * @param type ValidatorInterface $validator
     * @param type $name The name of the command to hook
     *   ('*' for all)
     */
    public function addInteractor(InteractorInterface $interactor, $name = '*')
    {
        $this->hooks[$name][self::INTERACT][] = $interactor;
    }

    /**
     * Add a pre-validator hook
     *
     * @param type ValidatorInterface $validator
     * @param type $name The name of the command to hook
     *   ('*' for all)
     */
    public function addPreValidator(ValidatorInterface $validator, $name = '*')
    {
        $this->hooks[$name][self::PRE_ARGUMENT_VALIDATOR][] = $validator;
    }

    /**
     * Add a validator hook
     *
     * @param type ValidatorInterface $validator
     * @param type $name The name of the command to hook
     *   ('*' for all)
     */
    public function addValidator(ValidatorInterface $validator, $name = '*')
    {
        $this->hooks[$name][self::ARGUMENT_VALIDATOR][] = $validator;
    }

    /**
     * Add a result processor.
     *
     * @param type ProcessResultInterface $resultProcessor
     * @param type $name The name of the command to hook
     *   ('*' for all)
     */
    public function addResultProcessor(ProcessResultInterface $resultProcessor, $name = '*')
    {
        $this->hooks[$name][self::PROCESS_RESULT][] = $resultProcessor;
    }

    /**
     * Add a result alterer. After a result is processed
     * by a result processor, an alter hook may be used
     * to convert the result from one form to another.
     *
     * @param type AlterResultInterface $resultAlterer
     * @param type $name The name of the command to hook
     *   ('*' for all)
     */
    public function addAlterResult(AlterResultInterface $resultAlterer, $name = '*')
    {
        $this->hooks[$name][self::ALTER_RESULT][] = $resultAlterer;
    }

    /**
     * Add a status determiner. Usually, a command should return
     * an integer on error, or a result object on success (which
     * implies a status code of zero). If a result contains the
     * status code in some other field, then a status determiner
     * can be used to call the appropriate accessor method to
     * determine the status code.  This is usually not necessary,
     * though; a command that fails may return a CommandError
     * object, which contains a status code and a result message
     * to display.
     * @see CommandError::getExitCode()
     *
     * @param type StatusDeterminerInterface $statusDeterminer
     * @param type $name The name of the command to hook
     *   ('*' for all)
     */
    public function addStatusDeterminer(StatusDeterminerInterface $statusDeterminer, $name = '*')
    {
        $this->hooks[$name][self::STATUS_DETERMINER][] = $statusDeterminer;
    }

    /**
     * Add an output extractor. If a command returns an object
     * object, by default it is passed directly to the output
     * formatter (if in use) for rendering. If the result object
     * contains more information than just the data to render, though,
     * then an output extractor can be used to call the appopriate
     * accessor method of the result object to get the data to
     * rendered.  This is usually not necessary, though; it is preferable
     * to have complex result objects implement the OutputDataInterface.
     * @see OutputDataInterface::getOutputData()
     *
     * @param type ExtractOutputInterface $outputExtractor
     * @param type $name The name of the command to hook
     *   ('*' for all)
     */
    public function addOutputExtractor(ExtractOutputInterface $outputExtractor, $name = '*')
    {
        $this->hooks[$name][self::EXTRACT_OUTPUT][] = $outputExtractor;
    }

    public function interact(
        InputInterface $input,
        OutputInterface $output,
        $names,
        AnnotationData $annotationData
    ) {
        $interactors = $this->getInteractors($names, $annotationData);
        foreach ($interactors as $interactor) {
            $this->callInteractor($interactor, $input, $output, $annotationData);
        }
    }

    public function validateArguments($names, $args, AnnotationData $annotationData)
    {
        $validators = $this->getValidators($names, $annotationData);
        foreach ($validators as $validator) {
            $validated = $this->callValidator($validator, $args, $annotationData);
            if (is_object($validated)) {
                return $validated;
            }
            if (is_array($validated)) {
                $args = $validated;
            }
        }
        return $args;
    }

    /**
     * Process result and decide what to do with it.
     * Allow client to add transformation / interpretation
     * callbacks.
     */
    public function alterResult($names, $result, $args, AnnotationData $annotationData)
    {
        $processors = $this->getProcessResultHooks($names, $annotationData);
        foreach ($processors as $processor) {
            $result = $this->callProcessor($processor, $result, $args, $annotationData);
        }
        $alterers = $this->getAlterResultHooks($names, $annotationData);
        foreach ($alterers as $alterer) {
            $result = $this->callProcessor($alterer, $result, $args, $annotationData);
        }

        return $result;
    }

    /**
     * Call all status determiners, and see if any of them
     * know how to convert to a status code.
     */
    public function determineStatusCode($names, $result)
    {
        // If the result (post-processing) is an object that
        // implements ExitCodeInterface, then we will ask it
        // to give us the status code.
        if ($result instanceof ExitCodeInterface) {
            return $result->getExitCode();
        }

        // If the result does not implement ExitCodeInterface,
        // then we'll see if there is a determiner that can
        // extract a status code from the result.
        $determiners = $this->getStatusDeterminers($names);
        foreach ($determiners as $determiner) {
            $status = $this->callDeterminer($determiner, $result);
            if (isset($status)) {
                return $status;
            }
        }
    }

    /**
     * Convert the result object to printable output in
     * structured form.
     */
    public function extractOutput($names, $result)
    {
        if ($result instanceof OutputDataInterface) {
            return $result->getOutputData();
        }

        $extractors = $this->getOutputExtractors($names);
        foreach ($extractors as $extractor) {
            $structuredOutput = $this->callExtractor($extractor, $result);
            if (isset($structuredOutput)) {
                return $structuredOutput;
            }
        }

        return $result;
    }

    protected function getInteractors($names, AnnotationData $annotationData)
    {
        return $this->getHooks($names, self::INTERACT, $annotationData);
    }

    protected function getValidators($names, AnnotationData $annotationData)
    {
        return $this->getHooks($names, self::ARGUMENT_VALIDATOR, $annotationData);
    }

    protected function getStatusDeterminers($names)
    {
        return $this->getHooks($names, self::STATUS_DETERMINER);
    }

    protected function getProcessResultHooks($names, AnnotationData $annotationData)
    {
        return $this->getHooks($names, self::PROCESS_RESULT, $annotationData);
    }

    protected function getAlterResultHooks($names, AnnotationData $annotationData)
    {
        return $this->getHooks($names, self::ALTER_RESULT, $annotationData);
    }

    protected function getOutputExtractors($names)
    {
        return $this->getHooks($names, self::EXTRACT_OUTPUT);
    }

    protected function getCommandEvents($names)
    {
        return $this->getHooks($names, self::COMMAND_EVENT);
    }

    /**
     * Get a set of hooks with the provided name(s). Include the
     * pre- and post- hooks, and also include the global hooks ('*')
     * in addition to the named hooks provided.
     *
     * @param string|array $names The name of the function being hooked.
     * @param string $hook The specific hook name (e.g. alter)
     *
     * @return callable[]
     */
    protected function getHooks($names, $hook, $annotationData = null)
    {
        $names = array_merge(
            (array)$names,
            ($annotationData == null) ? [] : array_map(function ($item) {
                return "@$item";
            }, $annotationData->keys())
        );
        $names[] = '*';
        return array_merge(
            $this->get($names, "pre-$hook"),
            $this->get($names, $hook),
            $this->get($names, "post-$hook")
        );
    }

    /**
     * Get a set of hooks with the provided name(s).
     *
     * @param string|array $names The name of the function being hooked.
     * @param string $hook The specific hook name (e.g. alter)
     *
     * @return callable[]
     */
    public function get($names, $hook)
    {
        $hooks = [];
        foreach ((array)$names as $name) {
            $hooks = array_merge($hooks, $this->getHook($name, $hook));
        }
        return $hooks;
    }

    /**
     * Get a single named hook.
     *
     * @param string $name The name of the hooked method
     * @param string $hook The specific hook name (e.g. alter)
     *
     * @return callable[]
     */
    protected function getHook($name, $hook)
    {
        if (isset($this->hooks[$name][$hook])) {
            return $this->hooks[$name][$hook];
        }
        return [];
    }

    protected function callInteractor($interactor, $input, $output, AnnotationData $annotationData)
    {
        if ($interactor instanceof InteractorInterface) {
            return $interactor->interact($input, $output, $annotationData);
        }
        if (is_callable($interactor)) {
            return $interactor($input, $output, $annotationData);
        }
    }

    protected function callValidator($validator, $args, AnnotationData $annotationData)
    {
        // TODO: Adding AnnotationData to ValidatorInterface would be
        // a breaking change. Either hold off until 2.x, or make
        // a new interface containing a method that takes the extra parameter.
        if ($validator instanceof ValidatorInterface) {
            return $validator->validate($args);
        }
        if (is_callable($validator)) {
            return $validator($args, $annotationData);
        }
    }

    protected function callProcessor($processor, $result, $args, AnnotationData $annotationData)
    {
        $processed = null;
        // TODO: Adding AnnotationData to ProcessResultInterface would be
        // a breaking change. Either hold off until 2.x, or make
        // a new interface containing a method that takes the extra parameter.
        if ($processor instanceof ProcessResultInterface) {
            $processed = $processor->process($result, $args);
        }
        if (is_callable($processor)) {
            $processed = $processor($result, $args, $annotationData);
        }
        if (isset($processed)) {
            return $processed;
        }
        return $result;
    }

    protected function callDeterminer($determiner, $result)
    {
        if ($determiner instanceof StatusDeterminerInterface) {
            return $determiner->determineStatusCode($result);
        }
        if (is_callable($determiner)) {
            return $determiner($result);
        }
    }

    protected function callExtractor($extractor, $result)
    {
        if ($extractor instanceof ExtractOutputInterface) {
            return $extractor->extractOutput($result);
        }
        if (is_callable($extractor)) {
            return $extractor($result);
        }
    }

    /**
     * @param ConsoleCommandEvent $event
     */
    public function callCommandEventHooks(ConsoleCommandEvent $event)
    {
        /* @var Command $command */
        $command = $event->getCommand();
        $names = [$command->getName()];
        $commandEventHooks = $this->getCommandEvents($names);
        foreach ($commandEventHooks as $commandEvent) {
            if (is_callable($commandEvent)) {
                $commandEvent($event);
            }
        }
    }

    /**
     * @{@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [ConsoleEvents::COMMAND => 'callCommandEventHooks'];
    }
}
