<?php
namespace Robo\Collection;

use Robo\Config;
use Psr\Log\LogLevel;
use Robo\Contract\InflectionInterface;
use Robo\Contract\TaskInterface;
use Robo\Contract\CompletionInterface;
use Robo\Contract\WrappedTaskInterface;
use Robo\Task\Simulator;
use ReflectionClass;
use Robo\Task\BaseTask;
use Robo\Contract\BuilderAwareInterface;
use Robo\Contract\CommandInterface;

/**
 * Creates a collection, and adds tasks to it.  The collection builder
 * offers a streamlined chained-initialization mechanism for easily
 * creating task groups.  Facilities for creating working and temporary
 * directories are also provided.
 *
 * ``` php
 * <?php
 * $result = $this->collectionBuilder()
 *   ->taskFilesystemStack()
 *     ->mkdir('g')
 *     ->touch('g/g.txt')
 *   ->rollback(
 *     $this->taskDeleteDir('g')
 *   )
 *   ->taskFilesystemStack()
 *     ->mkdir('g/h')
 *     ->touch('g/h/h.txt')
 *   ->taskFilesystemStack()
 *     ->mkdir('g/h/i/c')
 *     ->touch('g/h/i/i.txt')
 *   ->run()
 * ?>
 *
 * In the example above, the `taskDeleteDir` will be called if
 * ```
 */
class CollectionBuilder extends BaseTask implements NestedCollectionInterface, WrappedTaskInterface, CommandInterface
{
    /**
     * @var \Robo\Tasks
     */
    protected $commandFile;

    /**
     * @var CollectionInterface
     */
    protected $collection;

    /**
     * @var TaskInterface
     */
    protected $currentTask;

    /**
     * @var bool
     */
    protected $simulated;

    /**
     * @param \Robo\Tasks $commandFile
     */
    public function __construct($commandFile)
    {
        $this->commandFile = $commandFile;
    }

    /**
     * @param bool $simulated
     *
     * @return $this
     */
    public function simulated($simulated = true)
    {
        $this->simulated = $simulated;
        return $this;
    }

    /**
     * @return bool
     */
    public function isSimulated()
    {
        if (!isset($this->simulated)) {
            $this->simulated = $this->getConfig()->get(Config::SIMULATE);
        }
        return $this->simulated;
    }

    /**
     * Create a temporary directory to work in. When the collection
     * completes or rolls back, the temporary directory will be deleted.
     * Returns the path to the location where the directory will be
     * created.
     *
     * @param string $prefix
     * @param string $base
     * @param bool $includeRandomPart
     *
     * @return string
     */
    public function tmpDir($prefix = 'tmp', $base = '', $includeRandomPart = true)
    {
        // n.b. Any task that the builder is asked to create is
        // automatically added to the builder's collection, and
        // wrapped in the builder object. Therefore, the result
        // of any call to `taskFoo()` from within the builder will
        // always be `$this`.
        return $this->taskTmpDir($prefix, $base, $includeRandomPart)->getPath();
    }

    /**
     * Create a working directory to hold results. A temporary directory
     * is first created to hold the intermediate results.  After the
     * builder finishes, the work directory is moved into its final location;
     * any results already in place will be moved out of the way and
     * then deleted.
     *
     * @param string $finalDestination The path where the working directory
     *   will be moved once the task collection completes.
     *
     * @return string
     */
    public function workDir($finalDestination)
    {
        // Creating the work dir task in this context adds it to our task collection.
        return $this->taskWorkDir($finalDestination)->getPath();
    }

    public function addTask(TaskInterface $task)
    {
        $this->getCollection()->add($task);
        return $this;
    }

    public function addCode(callable $code)
    {
        $this->getCollection()->addCode($code);
        return $this;
    }

    /**
     * Add a list of tasks to our task collection.
     *
     * @param TaskInterface[] $tasks
     *   An array of tasks to run with rollback protection
     *
     * @return $this
     */
    public function addTaskList(array $tasks)
    {
        $this->getCollection()->addTaskList($tasks);
        return $this;
    }

    public function rollback(TaskInterface $task)
    {
        // Ensure that we have a collection if we are going to add
        // a rollback function.
        $this->getCollection()->rollback($task);
        return $this;
    }

    public function rollbackCode(callable $rollbackCode)
    {
        $this->getCollection()->rollbackCode($rollbackCode);
        return $this;
    }

    public function completion(TaskInterface $task)
    {
        $this->getCollection()->completion($task);
        return $this;
    }

    public function completionCode(callable $completionCode)
    {
        $this->getCollection()->completionCode($completionCode);
        return $this;
    }

    /**
     * @param string $text
     * @param array $context
     * @param string $level
     *
     * @return $this
     */
    public function progressMessage($text, $context = [], $level = LogLevel::NOTICE)
    {
        $this->getCollection()->progressMessage($text, $context, $level);
        return $this;
    }

    /**
     * @param \Robo\Collection\NestedCollectionInterface $parentCollection
     *
     * @return $this
     */
    public function setParentCollection(NestedCollectionInterface $parentCollection)
    {
        $this->getCollection()->setParentCollection($parentCollection);
        return $this;
    }

    /**
     * Called by the factory method of each task; adds the current
     * task to the task builder.
     *
     * TODO: protected
     *
     * @param TaskInterface $task
     *
     * @return $this
     */
    public function addTaskToCollection($task)
    {
        // Postpone creation of the collection until the second time
        // we are called. At that time, $this->currentTask will already
        // be populated.  We call 'getCollection()' so that it will
        // create the collection and add the current task to it.
        // Note, however, that if our only tasks implements NestedCollectionInterface,
        // then we should force this builder to use a collection.
        if (!$this->collection && (isset($this->currentTask) || ($task instanceof NestedCollectionInterface))) {
            $this->getCollection();
        }
        $this->currentTask = $task;
        if ($this->collection) {
            $this->collection->add($task);
        }
        return $this;
    }

    /**
     * Return the current task for this collection builder.
     * TODO: Not needed?
     *
     * @return \Robo\Contract\TaskInterface
     */
    public function getCollectionBuilderCurrentTask()
    {
        return $this->currentTask;
    }

    /**
     * Create a new builder with its own task collection
     *
     * @return CollectionBuilder
     */
    public function newBuilder()
    {
        $collectionBuilder = new self($this->commandFile);
        $collectionBuilder->inflect($this);
        $collectionBuilder->simulated($this->isSimulated());
        return $collectionBuilder;
    }

    /**
     * Calling the task builder with methods of the current
     * task calls through to that method of the task.
     *
     * There is extra complexity in this function that could be
     * simplified if we attached the 'LoadAllTasks' and custom tasks
     * to the collection builder instead of the RoboFile.  While that
     * change would be a better design overall, it would require that
     * the user do a lot more work to set up and use custom tasks.
     * We therefore take on some additional complexity here in order
     * to allow users to maintain their tasks in their RoboFile, which
     * is much more convenient.
     *
     * Calls to $this->collectionBuilder()->taskFoo() cannot be made
     * directly because all of the task methods are protected.  These
     * calls will therefore end up here.  If the method name begins
     * with 'task', then it is eligible to be used with the builder.
     *
     * When we call getBuiltTask, below, it will use the builder attached
     * to the commandfile to build the task. However, this is not what we
     * want:  the task needs to be built from THIS collection builder, so that
     * it will be affected by whatever state is active in this builder.
     * To do this, we have two choices: 1) save and restore the builder
     * in the commandfile, or 2) clone the commandfile and set this builder
     * on the copy. 1) is vulnerable to failure in multithreaded environments
     * (currently not supported), while 2) might cause confusion if there
     * is shared state maintained in the commandfile, which is in the
     * domain of the user.
     *
     * Note that even though we are setting up the commandFile to
     * use this builder, getBuiltTask always creates a new builder
     * (which is constructed using all of the settings from the
     * commandFile's builder), and the new task is added to that.
     * We therefore need to transfer the newly built task into this
     * builder. The temporary builder is discarded.
     *
     * @param string $fn
     * @param array $args
     *
     * @return $this|mixed
     */
    public function __call($fn, $args)
    {
        if (preg_match('#^task[A-Z]#', $fn) && (method_exists($this->commandFile, 'getBuiltTask'))) {
            $saveBuilder = $this->commandFile->getBuilder();
            $this->commandFile->setBuilder($this);
            $temporaryBuilder = $this->commandFile->getBuiltTask($fn, $args);
            $this->commandFile->setBuilder($saveBuilder);
            if (!$temporaryBuilder) {
                throw new \BadMethodCallException("No such method $fn: task does not exist in " . get_class($this->commandFile));
            }
            $temporaryBuilder->getCollection()->transferTasks($this);
            return $this;
        }
        if (!isset($this->currentTask)) {
            throw new \BadMethodCallException("No such method $fn: current task undefined in collection builder.");
        }
        // If the method called is a method of the current task,
        // then call through to the current task's setter method.
        $result = call_user_func_array([$this->currentTask, $fn], $args);

        // If something other than a setter method is called, then return its result.
        $currentTask = ($this->currentTask instanceof WrappedTaskInterface) ? $this->currentTask->original() : $this->currentTask;
        if (isset($result) && ($result !== $currentTask)) {
            return $result;
        }

        return $this;
    }

    /**
     * Construct the desired task and add it to this builder.
     *
     * @param string|object $name
     * @param array $args
     *
     * @return \Robo\Collection\CollectionBuilder
     */
    public function build($name, $args)
    {
        $reflection = new ReflectionClass($name);
        $task = $reflection->newInstanceArgs($args);
        if (!$task) {
            throw new RuntimeException("Can not construct task $name");
        }
        $task = $this->fixTask($task, $args);
        return $this->addTaskToCollection($task);
    }

    /**
     * @param InflectionInterface $task
     * @param array $args
     *
     * @return \Robo\Collection\CompletionWrapper|\Robo\Task\Simulator
     */
    protected function fixTask($task, $args)
    {
        $task->inflect($this);
        if ($task instanceof BuilderAwareInterface) {
            $task->setBuilder($this);
        }

        // Do not wrap our wrappers.
        if ($task instanceof CompletionWrapper || $task instanceof Simulator) {
            return $task;
        }

        // Remember whether or not this is a task before
        // it gets wrapped in any decorator.
        $isTask = $task instanceof TaskInterface;
        $isCollection = $task instanceof NestedCollectionInterface;

        // If the task implements CompletionInterface, ensure
        // that its 'complete' method is called when the application
        // terminates -- but only if its 'run' method is called
        // first.  If the task is added to a collection, then the
        // task will be unwrapped via its `original` method, and
        // it will be re-wrapped with a new completion wrapper for
        // its new collection.
        if ($task instanceof CompletionInterface) {
            $task = new CompletionWrapper(Temporary::getCollection(), $task);
        }

        // If we are in simulated mode, then wrap any task in
        // a TaskSimulator.
        if ($isTask && !$isCollection && ($this->isSimulated())) {
            $task = new \Robo\Task\Simulator($task, $args);
            $task->inflect($this);
        }

        return $task;
    }

    /**
     * When we run the collection builder, run everything in the collection.
     *
     * @return \Robo\Result
     */
    public function run()
    {
        $this->startTimer();
        $result = $this->runTasks();
        $this->stopTimer();
        $result['time'] = $this->getExecutionTime();
        return $result;
    }

    /**
     * If there is a single task, run it; if there is a collection, run
     * all of its tasks.
     *
     * @return \Robo\Result
     */
    protected function runTasks()
    {
        if (!$this->collection && $this->currentTask) {
            return $this->currentTask->run();
        }
        return $this->getCollection()->run();
    }

    /**
     * @return string
     */
    public function getCommand()
    {
        if (!$this->collection && $this->currentTask) {
            $task = $this->currentTask;
            $task = ($task instanceof WrappedTaskInterface) ? $task->original() : $task;
            if ($task instanceof CommandInterface) {
                return $task->getCommand();
            }
        }

        return $this->getCollection()->getCommand();
    }

    /**
     * @return \Robo\Collection\Collection
     */
    public function original()
    {
        return $this->getCollection();
    }

    /**
     * Return the collection of tasks associated with this builder.
     *
     * @return CollectionInterface
     */
    public function getCollection()
    {
        if (!isset($this->collection)) {
            $this->collection = new Collection();
            $this->collection->inflect($this);
            $this->collection->setProgressBarAutoDisplayInterval($this->getConfig()->get(Config::PROGRESS_BAR_AUTO_DISPLAY_INTERVAL));

            if (isset($this->currentTask)) {
                $this->collection->add($this->currentTask);
            }
        }
        return $this->collection;
    }
}
