def digit_at($value; $index):
  $value[$index:$index + 1] | tonumber;

def has_valid_isbn13_checksum:
  . as $isbn
  | (
      [
        range(0; 12) as $index
        | digit_at($isbn; $index)
          * (if ($index % 2) == 0 then 1 else 3 end)
      ]
      | add
    ) as $weighted_sum
  | digit_at($isbn; 12) == ((10 - ($weighted_sum % 10)) % 10);

if type != "object" then
  error("canonical locale must be a JSON object")
else
  [
    keys[]
    | select(test("^es\\. (?:978|979)[0-9]{10}$"))
    | sub("^es\\. "; "")
  ] as $examples
  | if ($examples | length) == 0 then
      error("canonical locale contains no explicit ISBN-13 example keys")
    elif ($examples | all(.[]; has_valid_isbn13_checksum)) | not then
      error("canonical locale contains an ISBN-13 example with an invalid checksum")
    else
      $examples | unique
    end
end
