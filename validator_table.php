<?php

define("ALLOWED", join('', range('a', 'z')) .
                  join('', range('A', 'Z')) .
                  '0123456789~!#$%&\'*+-/=?^_`{}|');

class State {
  const Initial = 0;
  const Atom = 1;
  const Quoted = 2;
  const Escaped = 3;
  const Postquote = 4;
  const Success = 5;
  const Error = 6;
  const Bracketed = 7;
}

function CharRange($start, $end)
{
  return join('', range(sprintf('%c', $start), sprintf('%c', $end)));
}

function NextState($char, $state, $tbl)
{
  if ($state === State::Error)
    return State::Error;

  foreach ($tbl[$state] as $match) {
    if (preg_match('/[' . preg_quote($match['match'], '/') . ']/', $char))
      return $match['nextState'];
  }
  return State::Error;
}

function MatchUser($address)
{
  $user_tbl =
    array(
        State::Initial =>
        array(
              array(
                    'match' => '"',
                    'nextState' => State::Quoted),
              array(
                    'match' => ALLOWED . chr(9) . chr(32),
                    'nextState' => State::Atom)),
        State::Atom =>
        array(
              array(
                    'match' => '.',
                    'nextState' => State::Initial),
              array(
                    'match' => ALLOWED . chr(9) . chr(32),
                    'nextState' => State::Atom),
              array(
                    'match' => '@',
                    'nextState' => State::Success)),
        State::Quoted =>
        array(
              array(
                    'match' => '\\',
                    'nextState' => State::Escaped),
              array(
                    'match' => '"',
                    'nextState' => State::Postquote),
              array(
                    // All printable ASCII except " and \, space, and tab
                    'match' => CharRange(32, 126) . chr(9),
                    'nextState' => State::Quoted)),
        State::Escaped =>
        array(
              array(
                    'match' => CharRange(32, 126) . chr(9),
                    'nextState' => State::Quoted)),
        State::Postquote =>
        array(
              array(
                    'match' => '@',
                    'nextState' => State::Success),
              array(
                    'match' => '.',
                    'nextState' => State::Initial)));

  $i = 0;
  $state = State::Initial;
  foreach (str_split($address) as $c) {
    $state = NextState($c, $state, $user_tbl);
    $i++;
    if ($state == State::Success)
      return $i;
  }
  return -1;
}

function MatchDomain($address)
{
  $domain_tbl =
    array(
          State::Initial =>
          array(
                array(
                      'match' => '[',
                      'nextState' => State::Bracketed),
                array(
                      'match' => ALLOWED . chr(9) . chr(32),
                      'nextState' => State::Atom)),
          State::Atom =>
          array(
                array(
                      'match' => '.',
                      'nextState' => State::Initial),
                array(
                      'match' => ALLOWED . chr(9) . chr(32),
                      'nextState' => State::Atom)),
          State::Bracketed =>
          array(
                array(
                      'match' => ']',
                      'nextState' => State::Success),
                array(
                      'match' => CharRange(32, 90) . CharRange(94, 126) . chr(9),
                      'nextState' => State::Bracketed)));

  $i = 0;
  $state = State::Initial;
  foreach (str_split($address) as $c) {
    $state = NextState($c, $state, $domain_tbl);
    $i++;
  }

  /* All input has to be consumed in order to match */
  if ($state == State::Success or $state == State::Atom)
    return True;
  return False;
}

function IsValidEmail($address)
{
  $i = MatchUser($address);
  if ($i < 0)
    return False;

  if (MatchDomain(substr($address, $i)))
    return True;
  return False;
}

$test_cases = array(
                    array("foo@baz.com", True),
                    array(".foo@baz.com", False),
                    array("foo.@baz.com", False),
                    array("\\.foo@baz.com", False),
                    array('".foo"@baz.com', True),
                    array('foo.\\@bar@baz.com', False),
                    array('"test\\\\blah"@example.com', True),
                    array('"test\\blah"@example.com', True),
                    array('\\"test\\\\\\rblah\\"@example.com', False),
                    array('\\"test\\rblah\\"@example.com', False),
                    array('"test\\"blah"@example.com', True),
                    array('"test"blah"@example.com', False),
                    array('NotAnEmail', False),
                    array('@NotAnEmail', False),
                    array('customer/department@example.com', True),
                    array('$A12345@example.com', True),
                    array('!def!xyz%abc@example.com', True),
                    array('_Yosemite.Sam@example.com', True),
                    array('~@example.com', True),
                    array('wo..oly@example.com', False),
                    array('.@example.com', False),
                    array('"Grover@Cleveland"@example.com', True),
                    array('"Grover Cleveland"@example.com', True),
                    array('Grover Cleveland@example.com', True));

echo "<html><head><title>Email Validation Test</title><style>.pass { background-color: green; } .fail { background-color: red; }</style></head><body>";
echo "<h1>Email Validation Testcases</h1>";
echo "<table><tr><th>Email</th><th>Expected Value</th><th>Test Result</th></tr>";
foreach ($test_cases as $tc) {
  if (IsValidEmail($tc[0]) === $tc[1])
    $res = '<td class="pass">PASS</td>';
  else
    $res = '<td class="fail">FAIL</td>';

  echo "<tr><td>" . $tc[0] . "</td>";
  if ($tc[1])
    echo "<td>True</td>";
  else
    echo "<td>False</td>";
  echo $res . "</tr>";
}
echo "</table></body></html>";
?>
