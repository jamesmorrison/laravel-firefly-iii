<?php

namespace FireflyIII\Database;


use Carbon\Carbon;
use Firefly\Exception\FireflyException;
use FireflyIII\Exception\NotImplementedException;
use Illuminate\Support\Collection;
use Illuminate\Support\MessageBag;
use LaravelBook\Ardent\Ardent;
use FireflyIII\Database\Ifaces\CommonDatabaseCalls;
use FireflyIII\Database\Ifaces\CUD;
use FireflyIII\Database\Ifaces\TransactionJournalInterface;

/**
 * Class TransactionJournal
 *
 * @package FireflyIII\Database
 */
class TransactionJournal implements TransactionJournalInterface, CUD, CommonDatabaseCalls
{
    use SwitchUser;

    /**
     *
     */
    public function __construct()
    {
        $this->setUser(\Auth::user());
    }

    /**
     * @param Carbon $date
     *
     * @return float
     */
    public function getSumOfIncomesByMonth(Carbon $date)
    {
        $end = clone $date;
        $date->startOfMonth();
        $end->endOfMonth();

        $sum = \DB::table('transactions')
                  ->leftJoin('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
                  ->leftJoin('transaction_types', 'transaction_journals.transaction_type_id', '=', 'transaction_types.id')
                  ->where('amount', '>', 0)
                  ->where('transaction_types.type', '=', 'Deposit')
                  ->where('transaction_journals.date', '>=', $date->format('Y-m-d'))
                  ->where('transaction_journals.date', '<=', $end->format('Y-m-d'))->sum('transactions.amount');
        $sum = floatval($sum);
        return $sum;
    }

    /**
     * @param Carbon $date
     *
     * @return float
     */
    public function getSumOfExpensesByMonth(Carbon $date)
    {
        $end = clone $date;
        $date->startOfMonth();
        $end->endOfMonth();

        $sum = \DB::table('transactions')
                  ->leftJoin('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
                  ->leftJoin('transaction_types', 'transaction_journals.transaction_type_id', '=', 'transaction_types.id')
                  ->where('amount', '>', 0)
                  ->where('transaction_types.type', '=', 'Withdrawal')
                  ->where('transaction_journals.date', '>=', $date->format('Y-m-d'))
                  ->where('transaction_journals.date', '<=', $end->format('Y-m-d'))->sum('transactions.amount');
        $sum = floatval($sum);
        return $sum;
    }

    /**
     * @param Carbon $start
     * @param Carbon $end
     *
     * @return Collection
     */
    public function getInDateRange(Carbon $start, Carbon $end)
    {
        return $this->getuser()->transactionjournals()->withRelevantData()->before($end)->after($start)->get();
    }

    /**
     * @return TransactionJournal
     */
    public function first()
    {
        return $this->getUser()->transactionjournals()->orderBy('date', 'ASC')->first();
    }


    /**
     * @param Ardent $model
     *
     * @return bool
     */
    public function destroy(Ardent $model)
    {
        // TODO: Implement destroy() method.
    }

    /**
     * Validates a model. Returns an array containing MessageBags
     * errors/warnings/successes.
     *
     * @param Ardent $model
     *
     * @return array
     */
    public function validateObject(Ardent $model)
    {
        // TODO: Implement validateObject() method.
    }

    /**
     * Validates an array. Returns an array containing MessageBags
     * errors/warnings/successes.
     *
     * @param array $model
     *
     * @return array
     */
    public function validate(array $model)
    {

        $warnings  = new MessageBag;
        $successes = new MessageBag;
        $errors    = new MessageBag;


        if (!isset($model['what'])) {
            $errors->add('description', 'Internal error: need to know type of transaction!');
        }
        if (isset($model['recurring_transaction_id']) && intval($model['recurring_transaction_id']) < 0) {
            $errors->add('recurring_transaction_id', 'Recurring transaction is invalid.');
        }
        if (!isset($model['description'])) {
            $errors->add('description', 'This field is mandatory.');
        }
        if (isset($model['description']) && strlen($model['description']) == 0) {
            $errors->add('description', 'This field is mandatory.');
        }
        if (isset($model['description']) && strlen($model['description']) > 255) {
            $errors->add('description', 'Description is too long.');
        }

        if (!isset($model['currency'])) {
            $errors->add('description', 'Internal error: currency is mandatory!');
        }
        if (isset($model['date']) && !($model['date'] instanceof Carbon) && strlen($model['date']) > 0) {
            try {
                new Carbon($model['date']);
            } catch (\Exception $e) {
                $errors->add('date', 'This date is invalid.');
            }
        }
        if (!isset($model['date'])) {
            $errors->add('date', 'This date is invalid.');
        }

        if (isset($model['to_id']) && intval($model['to_id']) < 0) {
            $errors->add('account_to', 'Invalid to-account');
        }
        if (isset($model['from_id']) && intval($model['from_id']) < 0) {
            $errors->add('account_from', 'Invalid from-account');
        }
        if (isset($model['to']) && !($model['to'] instanceof \Account)) {
            $errors->add('account_to', 'Invalid to-account');
        }
        if (isset($model['from']) && !($model['from'] instanceof \Account)) {
            $errors->add('account_from', 'Invalid from-account');
        }
        if (!isset($model['amount']) || (isset($model['amount']) && floatval($model['amount']) < 0)) {
            $errors->add('amount', 'Invalid amount');
        }
        if (!isset($model['from']) && !isset($model['to'])) {
            $errors->add('account_to', 'No accounts found!');
        }

        $validator = \Validator::make([$model], \Transaction::$rules);
        if ($validator->invalid()) {
            $errors->merge($errors);
        }


        /*
         * Add "OK"
         */
        if (!$errors->has('description')) {
            $successes->add('description', 'OK');
        }
        if (!$errors->has('date')) {
            $successes->add('date', 'OK');
        }
        return [
            'errors'    => $errors,
            'warnings'  => $warnings,
            'successes' => $successes
        ];


    }

    /**
     * @param array $data
     *
     * @return Ardent
     */
    public function store(array $data)
    {

        /** @var \FireflyIII\Database\TransactionType $typeRepository */
        $typeRepository = \App::make('FireflyIII\Database\TransactionType');

        /** @var \FireflyIII\Database\TransactionCurrency $currencyRepository */
        $currencyRepository = \App::make('FireflyIII\Database\TransactionCurrency');

        /** @var \FireflyIII\Database\Transaction $transactionRepository */
        $transactionRepository = \App::make('FireflyIII\Database\Transaction');

        $journalType = $typeRepository->findByWhat($data['what']);
        $currency    = $currencyRepository->findByCode($data['currency']);

        $journal = new \TransactionJournal;
        $journal->transactionType()->associate($journalType);
        $journal->transactionCurrency()->associate($currency);
        $journal->user()->associate($this->getUser());
        $journal->description = $data['description'];
        $journal->date        = $data['date'];
        $journal->completed   = 0;
        //$journal->user_id     = $this->getUser()->id;

        /*
         * This must be enough to store the journal:
         */
        if (!$journal->validate()) {
            \Log::error($journal->errors()->all());
            throw new FireflyException('store() transactionjournal failed, but it should not!');
        }
        $journal->save();

        /*
         *  Then store both transactions.
         */
        $first    = [
            'account'             => $data['from'],
            'transaction_journal' => $journal,
            'amount'              => ($data['amount'] * -1),
        ];
        $validate = $transactionRepository->validate($first);
        if ($validate['errors']->count() == 0) {
            $transactionRepository->store($first);
        } else {
            throw new FireflyException($validate['errors']->first());
        }

        $second = [
            'account'             => $data['to'],
            'transaction_journal' => $journal,
            'amount'              => floatval($data['amount']),
        ];

        $validate = $transactionRepository->validate($second);
        if ($validate['errors']->count() == 0) {
            $transactionRepository->store($second);
        } else {
            throw new FireflyException($validate['errors']->first());
        }

        $journal->completed = 1;
        $journal->save();
        return $journal;
    }

    /**
     * Returns an object with id $id.
     *
     * @param int $id
     *
     * @return Ardent
     */
    public function find($id)
    {
        return $this->getUser()->transactionjournals()->find($id);
    }

    /**
     * Returns all objects.
     *
     * @return Collection
     */
    public function get()
    {
        return $this->getUser()->transactionjournals()->get();
    }

    /**
     * Finds an account type using one of the "$what"'s: expense, asset, revenue, opening, etc.
     *
     * @param $what
     *
     * @return \AccountType|null
     */
    public function findByWhat($what)
    {
        // TODO: Implement findByWhat() method.
    }

    /**
     * @param array $ids
     *
     * @return Collection
     */
    public function getByIds(array $ids)
    {
        // TODO: Implement getByIds() method.
    }

    /**
     * @param Ardent $model
     * @param array  $data
     *
     * @return bool
     */
    public function update(Ardent $model, array $data)
    {
        // TODO: Implement update() method.
    }
}