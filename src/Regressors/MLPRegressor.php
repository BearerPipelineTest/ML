<?php

namespace Rubix\ML\Regressors;

use Rubix\ML\Online;
use Rubix\ML\Learner;
use Rubix\ML\Verbose;
use Rubix\ML\Estimator;
use Rubix\Tensor\Matrix;
use Rubix\ML\Persistable;
use Rubix\ML\Datasets\Dataset;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\NeuralNet\Snapshot;
use Rubix\ML\Other\Helpers\Params;
use Rubix\ML\NeuralNet\FeedForward;
use Rubix\ML\Other\Helpers\DataType;
use Rubix\ML\NeuralNet\Layers\Hidden;
use Rubix\ML\Other\Traits\LoggerAware;
use Rubix\ML\NeuralNet\Optimizers\Adam;
use Rubix\ML\Other\Traits\PredictsSingle;
use Rubix\ML\NeuralNet\Layers\Continuous;
use Rubix\ML\NeuralNet\Layers\Placeholder1D;
use Rubix\ML\NeuralNet\Optimizers\Optimizer;
use Rubix\ML\CrossValidation\Metrics\Metric;
use Rubix\ML\CrossValidation\Metrics\RSquared;
use Rubix\ML\NeuralNet\CostFunctions\LeastSquares;
use Rubix\ML\NeuralNet\CostFunctions\RegressionLoss;
use Rubix\ML\Other\Specifications\EstimatorIsCompatibleWithMetric;
use Rubix\ML\Other\Specifications\DatasetIsCompatibleWithEstimator;
use InvalidArgumentException;
use RuntimeException;

use const Rubix\ML\EPSILON;

/**
 * MLP Regressor
 *
 * A multi layer Neural Network with a continuous output layer suitable
 * for regression problems.
 *
 * > **Note**: The MLP features progress monitoring which stops training
 * when it can no longer make progress. It also utilizes snapshotting to
 * make sure that it always uses the best parameters even if progress may
 * have declined during training.
 *
 * References:
 * [1] G. E. Hinton. (1989). Connectionist learning procedures.
 * [2] L. Prechelt. (1997). Early Stopping - but when?
 *
 * @category    Machine Learning
 * @package     Rubix/ML
 * @author      Andrew DalPino
 */
class MLPRegressor implements Estimator, Learner, Online, Verbose, Persistable
{
    use PredictsSingle, LoggerAware;

    /**
     * The user-specified hidden layers of the network.
     *
     * @var array
     */
    protected $hidden = [
        //
    ];

    /**
     * The number of training samples to consider per iteholdoutn of gradient descent.
     *
     * @var int
     */
    protected $batchSize;

    /**
     * The gradient descent optimizer used to train the network.
     *
     * @var \Rubix\ML\NeuralNet\Optimizers\Optimizer
     */
    protected $optimizer;

    /**
     * The L2 regularization parameter.
     *
     * @var float
     */
    protected $alpha;

    /**
     * The maximum number of training epochs. i.e. the number of times to iterate
     * over the entire training set before the algorithm terminates.
     *
     * @var int
     */
    protected $epochs;

    /**
     * The minimum change in the cost function necessary to continue training.
     *
     * @var float
     */
    protected $minChange;

    /**
     * The function that computes the cost of an erroneous activation during
     * training.
     *
     * @var \Rubix\ML\NeuralNet\CostFunctions\RegressionLoss
     */
    protected $costFn;

    /**
     * The holdout of training samples to use for validation. i.e. the holdout
     * holdout.
     *
     * @var float
     */
    protected $holdout;

    /**
     * The number of epochs without improvement in the validation score to wait
     * before considering an early stop.
     *
     * @var int
     */
    protected $window;

    /**
     * The metric used to score the generalization performance of the model
     * during training.
     *
     * @var \Rubix\ML\CrossValidation\Metrics\Metric
     */
    protected $metric;

    /**
     * The underlying neural network instance.
     *
     * @var \Rubix\ML\NeuralNet\FeedForward|null
     */
    protected $network;

    /**
     * The validation scores at each epoch.
     *
     * @var array
     */
    protected $scores = [
        //
    ];

    /**
     * The average cost of a training sample at each epoch.
     *
     * @var array
     */
    protected $steps = [
        //
    ];

    /**
     * @param array $hidden
     * @param int $batchSize
     * @param \Rubix\ML\NeuralNet\Optimizers\Optimizer|null $optimizer
     * @param float $alpha
     * @param int $epochs
     * @param float $minChange
     * @param \Rubix\ML\NeuralNet\CostFunctions\RegressionLoss|null $costFn
     * @param float $holdout
     * @param int $window
     * @param \Rubix\ML\CrossValidation\Metrics\Metric|null $metric
     * @throws \InvalidArgumentException
     */
    public function __construct(
        array $hidden = [],
        int $batchSize = 100,
        ?Optimizer $optimizer = null,
        float $alpha = 1e-4,
        int $epochs = 1000,
        float $minChange = 1e-4,
        ?RegressionLoss $costFn = null,
        float $holdout = 0.1,
        int $window = 3,
        ?Metric $metric = null
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Cannot have less than 1 sample'
                . " per batch, $batchSize given.");
        }

        if ($alpha < 0.) {
            throw new InvalidArgumentException('L2 regularization amount must'
                . " be 0 or greater, $alpha given.");
        }

        if ($epochs < 1) {
            throw new InvalidArgumentException('Estimator must train for at'
                . " least 1 epoch, $epochs given.");
        }

        if ($minChange < 0.) {
            throw new InvalidArgumentException('Minimum change cannot be less'
                . " than 0, $minChange given.");
        }

        if ($holdout < 0.01 or $holdout > 0.5) {
            throw new InvalidArgumentException('Holdout ratio must be between'
                . " 0.01 and 0.5, $holdout given.");
        }

        if ($window < 1) {
            throw new InvalidArgumentException('Window must be at least 1'
                . " epoch, $window given.");
        }

        if ($metric) {
            EstimatorIsCompatibleWithMetric::check($this, $metric);
        }

        $this->hidden = $hidden;
        $this->batchSize = $batchSize;
        $this->optimizer = $optimizer ?? new Adam();
        $this->alpha = $alpha;
        $this->epochs = $epochs;
        $this->minChange = $minChange;
        $this->costFn = $costFn ?? new LeastSquares();
        $this->holdout = $holdout;
        $this->window = $window;
        $this->metric = $metric ?? new RSquared();
    }

    /**
     * Return the integer encoded estimator type.
     *
     * @return int
     */
    public function type() : int
    {
        return self::REGRESSOR;
    }

    /**
     * Return the data types that this estimator is compatible with.
     *
     * @return int[]
     */
    public function compatibility() : array
    {
        return [
            DataType::CONTINUOUS,
        ];
    }

    /**
     * Has the learner been trained?
     *
     * @return bool
     */
    public function trained() : bool
    {
        return isset($this->network);
    }

    /**
     * Return the validation scores at each epoch.
     *
     * @return array
     */
    public function scores() : array
    {
        return $this->scores;
    }

    /**
     * Return the average cost at every epoch.
     *
     * @return array
     */
    public function steps() : array
    {
        return $this->steps;
    }

    /**
     * Return the underlying neural network instance or null if not trained.
     *
     * @return \Rubix\ML\NeuralNet\FeedForward|null
     */
    public function network() : ?FeedForward
    {
        return $this->network;
    }

    /**
     * Train the estimator with a dataset.
     *
     * @param \Rubix\ML\Datasets\Dataset $dataset
     * @throws \InvalidArgumentException
     */
    public function train(Dataset $dataset) : void
    {
        if (!$dataset instanceof Labeled) {
            throw new InvalidArgumentException('Learner requires a'
                . ' labeled training set.');
        }

        $this->network = new FeedForward(
            new Placeholder1D($dataset->numColumns()),
            $this->hidden,
            new Continuous($this->alpha, $this->costFn),
            $this->optimizer
        );

        $this->scores = $this->steps = [];

        $this->partial($dataset);
    }

    /**
     * Train the network using mini-batch gradient descent with backpropagation.
     *
     * @param \Rubix\ML\Datasets\Dataset $dataset
     * @throws \InvalidArgumentException
     */
    public function partial(Dataset $dataset) : void
    {
        if (!$this->network) {
            $this->train($dataset);

            return;
        }

        if (!$dataset instanceof Labeled) {
            throw new InvalidArgumentException('Learner requires a'
                . ' labeled training set.');
        }

        DatasetIsCompatibleWithEstimator::check($dataset, $this);

        if ($this->logger) {
            $this->logger->info('Learner init ' . Params::stringify([
                'batch_size' => $this->batchSize,
                'optimizer' => $this->optimizer,
                'alpha' => $this->alpha,
                'epochs' => $this->epochs,
                'min_change' => $this->minChange,
                'cost_fn' => $this->costFn,
                'hold_out' => $this->holdout,
                'window' => $this->window,
                'metric' => $this->metric,
            ]));
        }

        [$testing, $training] = $dataset->randomize()->split($this->holdout);

        [$min, $max] = $this->metric->range();

        $bestScore = $min;
        $bestSnapshot = null;
        $prevLoss = INF;
        $nu = 0;

        for ($epoch = 1; $epoch <= $this->epochs; $epoch++) {
            $batches = $training->randomize()->batch($this->batchSize);

            $loss = 0.;

            foreach ($batches as $batch) {
                $loss += $this->network->roundtrip($batch);
            }

            $loss /= count($batches);

            $predictions = $this->predict($testing);

            $score = $this->metric->score($predictions, $testing->labels());

            $this->steps[] = $loss;
            $this->scores[] = $score;

            if ($this->logger) {
                $this->logger->info("Epoch $epoch score=$score loss=$loss");
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSnapshot = new Snapshot($this->network);

                $nu = 0;
            } else {
                $nu++;
            }

            if (is_nan($loss) or is_nan($score)) {
                break 1;
            }

            if ($loss < EPSILON or $score >= $max) {
                break 1;
            }

            if (abs($prevLoss - $loss) < $this->minChange) {
                break 1;
            }

            if ($nu >= $this->window) {
                break 1;
            }

            $prevLoss = $loss;
        }

        if (end($this->scores) < $bestScore) {
            if ($bestSnapshot) {
                $this->network->restore($bestSnapshot);

                if ($this->logger) {
                    $this->logger->info('Network restored from'
                        . 'previous snapshot');
                }
            }
        }

        if ($this->logger) {
            $this->logger->info('Training complete');
        }
    }

    /**
     * Feed a sample through the network and make a prediction based on the
     * activation of the output neuron.
     *
     * @param \Rubix\ML\Datasets\Dataset $dataset
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @return array
     */
    public function predict(Dataset $dataset) : array
    {
        if (!$this->network) {
            throw new RuntimeException('The estimator has not'
                . ' been trained.');
        }

        DatasetIsCompatibleWithEstimator::check($dataset, $this);

        $xT = Matrix::quick($dataset->samples())->transpose();

        return $this->network->infer($xT)->row(0);
    }
}
