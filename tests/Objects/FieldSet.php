<?php


namespace Tests\Objects;

/**
 * Class FieldSet
 */
class FieldSet
{
    public ?array $fields;
    public ?array $parameters;
    public string $title;

    /**
     * FieldSet constructor.
     */
    public function __construct()
    {
        $this->fields     = [];
        $this->parameters = [];
    }

    /**
     * @param Field       $field
     * @param string|null $key
     */
    public function addField(Field $field, ?string $key = null): void
    {
        if (null === $key) {
            $this->fields[] = $field;

            return;
        }
        $this->fields[$key] = $field;
    }

}