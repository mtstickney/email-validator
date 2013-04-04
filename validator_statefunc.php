<?php
define("ALLOWED", join('', range('a', 'z')) .
                  join('', range('A', 'Z')) .
                  '0123456789~!#$%&\'*+-/=?^_`{}|');
define("QALLOWED", join('', array(CharRange(1, 8), chr(11), chr(12),
                                  CharRange(14, 31), chr(33), CharRange(35, 91),
                                  CharRange(93, 127))));
define("QPALLOWED", CharRange(0, 127));
define("DTEXT", join('', array(CharRange(1, 8), chr(11), chr(12),
                               CharRange(14, 31), CharRange(33, 90),
                               CharRange(94, 127))));


function CharRange($start, $end)
{
  return join('', range(sprintf('%c', $start), sprintf('%c', $end)));
}

function NextState($c, $tbl) {
  foreach ($tbl as $m => $f) {
    if (strstr($m, $c))
      return $f;
  }
  return 'MatchError';
}

/* Local portion */
function MatchDottedUser($pos, $addr) {
  $t = array('"' => 'MatchQuoted',
             ALLOWED => 'MatchAtom');
  return NextState($addr[$pos], $t);
}

function MatchAtom($pos, $addr) {
  $t = array('@' => 'MatchDomain',
             '.' => 'MatchDottedUser',
             ALLOWED => 'MatchAtom');
  return NextState($addr[$pos], $t);
}

function MatchQuoted($pos, $addr) {
  $t = array('"' => 'MatchDotOrAt',
             '\\' => 'MatchEscaped',
             QALLOWED => 'MatchQuoted');
  return NextState($addr[$pos], $t);
}

function MatchDotOrAt($pos, $addr) {
  $t = array('@' => 'MatchDomain',
             '.' => 'MatchDottedUser');
  return NextState($addr[$pos], $t);
}

function MatchEscaped($pos, $addr) {
  $t = array(QPALLOWED => 'MatchQuoted');
  return NextState($addr[$pos], $t);
}

/* Domain portion */
function MatchDomain($pos, $addr) {
  $t = array(ALLOWED => 'MatchDottedDomain',
             '[' => 'MatchDText');
  return NextState($addr[$pos], $t);
}

function MatchDottedDomain($pos, $addr) {
  $t = array(ALLOWED => 'MatchDottedDomain',
             '.' => 'MatchAtomText');
  return NextState($addr[$pos], $t);
}

function MatchAtomText($pos, $addr) {
  $t = array(ALLOWED => 'MatchDottedDomain');
  return NextState($addr[$pos], $t);
}

function MatchDText($pos, $addr) {
  $t = array('\\' => 'MatchQP',
             ']' => 'MatchSuccess',
             DTEXT => 'MatchDText');
  return NextState($addr[$pos], $t);
}

function MatchSuccess($pos, $addr) {
  /* All input must be consumed to be an accepting state */
  return 'MatchError';
}

function MatchError($pos, $addr) {
  return 'MatchError';
}

function MatchQP($pos, $addr) {
  $t = array(QPAllOWED => 'MatchDText');
  return NextState($addr[$pos], $t);
}

function IsValidEmail($addr) {
  $state = 'MatchDottedUser';
  for ($pos=0; $pos < strlen($addr); $pos++) {
    $oldstate = $state;
    $state = $state($pos, $addr);
    if ($state === 'MatchError')
      echo "<ul><li>Error in state " . $oldstate . " at position " . $pos . "</li></ul>";
  }
  return $state === 'MatchSuccess' or $state === 'MatchDottedDomain';
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
  array('_Yosemite.Sam@example.com', True),
  array('~@example.com', True),
  array('wo..oly@example.com', False),
  array('.@example.com', False),
  array('"Grover@Cleveland"@example.com', True),
  array('"Grover Cleveland"@example.com', False),
  array('Grover Cleveland@example.com', False),
  array("dclo@us.ibm.com", True),
  array("abc\\@def@example.com", False),
  array("abc\\\\@example.com", False),
  array("Fred\\ Bloggs@example.com", False),
  array("Joe.\\\\Blow@example.com", False),
  array("\"Abc@def\"@example.com", True),
  array("\"Fred Bloggs\"@example.com", False),
  array("customer/department=shipping@example.com", True),
  array("\$A12345@example.com", True),
  array("!def!xyz%abc@example.com", True),
  array("_somename@example.com", True),
  array("user+mailbox@example.com", True),
  array("peter.piper@example.com", True),
  array("abc@def@example.com", False),
  array("abc\\\\@def@example.com", False),
  array("abc\\@example.com", False),
  array("@example.com", False),
  array("doug@", False),
  array("\"qu@example.com", False),
  array("ote\"@example.com", False),
  array(".dot@example.com", False),
  array("dot.@example.com", False),
  array("two..dot@example.com", False),
  array("\"Doug \"Ace\" L.\"@example.com", False),
  array("Doug\\ \\\"Ace\\\"\\ L\\.@example.com", False),
  array("hello world@example.com", False),
  array("gatsby@f.sc.ot.t.f.i.tzg.era.l.d.", False));

echo "<html><head><title>Email Validation Test</title><style>.pass { background-color: green; } .fail { background-color: red; }</style></head><body>";
echo "<h1>Email Validation Testcases</h1>";
echo "<table><tr><th>Email</th><th>Expected Value</th><th>Test Result</th></tr>";
foreach ($test_cases as $tc) {
  echo "Trying test case " . $tc[0] . "\n";
  if (count($tc) != 2)
    echo "Oh god, " . $tc[0] . " doesn't have 2 fields!!!\n";
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