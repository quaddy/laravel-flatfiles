<?php

namespace LaravelFlatfiles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaravelFlatfiles\StreamFilters\RemoveSequence;
use League\Csv\CannotInsertRecord;
use League\Csv\Writer;
use League\Flysystem\Adapter\Local;

class FlatfileExport
{
    /** @var FlatfileExportConfiguration $configuration */
    public $configuration;

    /** @var FilesystemAdapter $disk */
    protected $disk;

    /** @var Writer $writer */
    protected $writer;

    /** @var string $pathToFileOnDisk */
    protected $pathToFileOnDisk;

    /** @var string $pathToFile */
    public $pathToLocalTmpFile;

    /** @var callable|null */
    protected $beforeEachRowCallback;

    protected $bomNeedsToBeAdded = false;

    public function __construct(FlatfileExportConfiguration $configuration, FlatfileFields $fields = null)
    {
        $this->configuration = $configuration;

        if ($fields !== null) {
            $this->withFields($fields);
        }

        if ($this->configuration->get('csv', 'bom')) {
            $this->bomNeedsToBeAdded = true;
        }
    }

    public function withFields(FlatfileFields $flatfileFields)
    {
        $this->configuration->fields($flatfileFields->fields());

        return $this;
    }

    /**
     * @param  string  $targetFilename
     * @param  FilesystemAdapter|string  $disk  The disk object or the name of it
     *
     * @return FlatfileExport
     */
    public function to(String $targetFilename, $disk)
    {
        $this->pathToFileOnDisk = $targetFilename;
        $this->disk = is_string($disk) ? Storage::disk($disk) : $disk;

        $this->determineDefaultWriter();

        return $this;
    }

    /**
     * You can set a file location for the temporary file used to generate the export file. It's only locally, because
     * we're using a streaming API.
     *
     * @param  string  $tempFilepath  Absolut path to local disk to store a local temp file (before moving to final location)
     *
     * @return $this
     */
    public function usingLocalTmpFile(String $tempFilepath)
    {
        $this->pathToLocalTmpFile = $tempFilepath;

        return $this;
    }

    public function beforeEachRow(callable $callback)
    {
        $this->beforeEachRowCallback = $callback;

        return $this;
    }

    /**
     * @param  Collection|Model[]  $models
     *
     * @throws CannotInsertRecord
     */
    public function addRows(Collection $models)
    {
        foreach ($models as $model) {
            $this->addRow($model);
        }
    }

    /**
     * @param  Model  $model
     * @param  string|array  $relations  Name of child relation in model
     * @param  string  $alias  Name of attribute set with each model
     *
     * @return void
     * @throws CannotInsertRecord
     */
    public function addRowForEachRelation(Model $model, $relations, string $alias)
    {
        $relations = !is_array($relations) ? [$relations] : $relations;
        $hasRelation = false;

        foreach ($relations as $relation) {
            $relation = data_get($model, $relation);

            foreach ($relation as $relationModel) {
                $hasRelation = true;
                $model->$alias = $relationModel;
                $this->addRow($model);
                unset($model->$alias);
            }
        }

        // has no relations, insert only one row
        if (!$hasRelation) {
            $this->addRow($model);
        }
    }

    /**
     * @param  Model|Collection  $model
     *
     * @throws CannotInsertRecord
     */
    public function addRow($model)
    {
        if (false === $this->applyRowCallback($model)) {
            return;
        }

        $fields = $this->configuration->fields();
        $dataAsArray = $this->toArrayWithoutSnakeCasedKeys($this->makeModelAttributesVisible($model));

        // Grap values for each column from arrayed model (including relations)
        $this->writer->insertOne($fields->map(function (array $fieldConfigData) use ($dataAsArray, $model) {
            // Get value from arrayed model by column defintion
            $value = Arr::get($dataAsArray, Arr::get($fieldConfigData, 'column'));

            if ($callback = Arr::get($fieldConfigData, 'callback')) {
                $value = $callback($value, $model) ?? $value;
            }

            return $value;
        })->toArray());
    }

    public function addHeader()
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->writer->insertOne($this->configuration->fieldLabels());
    }

    public function moveToTarget()
    {
        $this->addBomIfNeeded();

        if ($this->disk->getAdapter() instanceof Local && $this->disk->path($this->pathToFileOnDisk) == $this->pathToLocalTmpFile) {
            // No temp file that has be moved
            return true;
        }

        if ($this->disk->putStream($this->pathToFileOnDisk, fopen($this->pathToLocalTmpFile, 'r'))) {
            return unlink($this->pathToLocalTmpFile);
        }

        return false;
    }

    private function determineDefaultWriter()
    {
        $writer = null;

        switch ($extension = $this->targetfileExtension()) {
            case 'csv':
                if (!$this->pathToLocalTmpFile) {
                    if ($this->disk->getAdapter() instanceof Local) {
                        $this->pathToLocalTmpFile = $this->disk->path($this->pathToFileOnDisk);
                    } else {
                        $this->pathToLocalTmpFile = tempnam(sys_get_temp_dir(), 'ffe');
                    }
                }

                $this->writer = Writer::createFromPath($this->pathToLocalTmpFile, 'w+');
                /** @noinspection PhpUnhandledExceptionInspection */
                $this->writer->setDelimiter($this->configuration->get('csv', 'delimiter'));
                /** @noinspection PhpUnhandledExceptionInspection */
                $this->writer->setEnclosure($this->configuration->get('csv', 'enclosure'));

                if ($this->configuration->get('csv', 'force_enclosure')) {
                    $this->addForceEnclosure();
                }

                $this->writer->setOutputBOM($this->configuration->get('csv', 'bom') ? Writer::BOM_UTF8 : '');
                break;
            default:
                throw new \RuntimeException('Unsupported file type: .'.$extension);
        }

        return $this;
    }

    protected function targetfileExtension()
    {
        return Str::lower(pathinfo($this->pathToFileOnDisk, PATHINFO_EXTENSION));
    }

    /**
     * @return FilesystemAdapter
     */
    public function disk()
    {
        return $this->disk;
    }

    public function configuration()
    {
        return $this->configuration;
    }

    private function applyRowCallback(&$model)
    {
        $callback = $this->beforeEachRowCallback;

        if (is_callable($callback)) {
            return $callback($model);
        }

        return true;
    }

    /**
     * @param  Model|Collection  $model
     *
     * @return Model|Collection
     */
    private function makeModelAttributesVisible($model)
    {
        if (!($model instanceof Model)) {
            return $model;
        }

        return $model->makeVisible($this->configuration->columns());
    }

    private function usesDisk()
    {
        return $this->disk() !== null;
    }

    private function addBomIfNeeded()
    {
        if ($this->bomNeedsToBeAdded && !$this->checkbom()) {
            file_put_contents($this->pathToLocalTmpFile, Writer::BOM_UTF8.file_get_contents($this->pathToLocalTmpFile));
            $this->bomNeedsToBeAdded = false;
        }
    }

    public function checkbom()
    {
        $str = file_get_contents($this->pathToLocalTmpFile);
        $bom = pack('CCC', 0xef, 0xbb, 0xbf);

        return 0 === strncmp($str, $bom, 3);
    }

    private function toArrayWithoutSnakeCasedKeys($model)
    {
        if (!($model instanceof Model)) {
            return $model->toArray();
        }

        $snake = $model::$snakeAttributes;

        $model::$snakeAttributes = false;
        $dataAsArray = $model->toArray();
        $model::$snakeAttributes = $snake;

        return $dataAsArray;
    }

    /**
     * adding an StreamFilter to force the enclosure of each cell.
     */
    private function addForceEnclosure()
    {
        $sequence = "\t\x1f";
        $addSequence = function (array $row) use ($sequence) {
            $res = [];
            foreach ($row as $value) {
                $res[] = $sequence.$value;
            }

            return $res;
        };

        $this->writer->addFormatter($addSequence);
        RemoveSequence::registerStreamFilter();
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->writer->addStreamFilter(RemoveSequence::createFilterName($this->writer, $sequence));
    }

    /**
     * @param  string|null  $filename
     * @param  array  $headers
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function downloadResponse(string $filename = null, array $headers = [])
    {
        return $this->disk()->download($this->pathToFileOnDisk, $filename, $headers);
    }
}
