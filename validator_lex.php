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

class ItemType
{
  const Local = 0;
  const Domain = 1;
  const EOF = 2;
  const Atom = 3;
  const Dot = 4;
  const AtSign = 5;
  const LeftBracket = 6;
  const Error = 7;
  const QuotedText = 8;
  const Quote = 9;
  const Escaped = 10;
  const InsideBracketed = 11;
  const RightBracket = 12;
}

define('EOF', -1);

class Item
{
  public $type;
  public $val;

  public function __construct($type, $val) {
    $this->type = $type;
    $this->val = $val;
  }

  public function __toString() {
    if ($this->type === TokenType::EOF)
      return "EOF";
    return join('', $this->val);
  }
}

class Lexer
{
  public $name;
  public $input;
  public $start = 0;
  public $pos = 0;
  public $items = array();
  public $width = 1;
  public $state;

  public function next_item() {
    while (True) {
      if (count($this->items) > 0)
        return array_shift($this->items);
      $statefunc = $this->state;
      $this->state = $statefunc($this);
    }
  }

  public function emit($type) {
    array_push($this->items,
               new Item($type,
                        array_slice($this->input,
                                    $this->start, $this->pos)));
    $this->start = $this->pos;
  }

  public function next() {
    if ($this->pos >= count($this->input)) {
      $this->width = 0;
      return EOF;
    }
    $c = $this->input[$this->pos];
    $this->pos += $this->width;
    return $c;
  }

  public function backup() {
    $this->pos -= $this->width;
  }

  public function ignore() {
    $this->start = $this->pos;
  }

  public function peek() {
    $c = $this->next();
    $this->backup();
    return $c;
  }

  public function accept($chars) {
    if (strstr($chars, $this->next()))
      return True;
    $this->backup();
    return False;
  }

  public function accept_run($chars) {
    while (strstr($chars, $this->next())) {}
    $this->backup();
  }

  public function errorf($format) {
    $args = func_get_args();
    array_shift($args);
    array_push($this->items, new Item(ItemType::Error, vsprintf($format, $args)));
    return NULL;
  }
}

function LexEmail($name, $input) {
  $l = new Lexer();
  $l->name = $name;
  $l->input = str_split($input);
  $l->state = 'LexAtomish';
  return $l;
}

function LexAtomish($l) {
  $c = $l->next();
  if ($c === '"') {
    return 'LexOpenQuote';
  }
  else {
    $l->backup();
    return 'LexAtom';
  }
}

function LexAtom($l) {
  $l->accept_run(ALLOWED);
  if ($l->pos > $l->start)
    $l->emit(ItemType::Atom);

  $c = $l->next();
  if ($c === '.')
    return 'LexDot';
  if ($c === '@')
    return 'LexAt';
  if ($c === EOF) {
    $l->emit(ItemType::EOF);
    return NULL;
  }
  return $l->errorf("Unexpected input '%s' after atom", $c);
}

function LexOpenQuote($l) {
  $l->emit(ItemType::Quote);
  return 'LexInsideQuoted';
}

function LexInsideQuoted($l) {
  while (True) {
    $l->accept_run(QALLOWED);

    $c = $l->next();
    if ($c === '\\') {
      if ($l->pos > $l->start)
        $l->emit(ItemType::QuotedText);
      return 'LexEscaped';
    }
    if ($c === '"') {
      if ($l->pos > $l->start)
        $l->emit(ItemType::QuotedText);
      return 'LexCloseQuote';
    }
    return $l->errorf("Non-printable character asc(%d) in quoted", ord($c));
  }
}

function LexCloseQuote($l) {
  $l->emit(ItemType::Quote);
  $c = $l->next();
  if ($c === '@')
    return 'LexAt';
  if ($c === '.')
    return 'LexDot';
  if ($c === EOF) {
    $l->emit(ItemType::EOF);
    return NULL;
  }

  return $l->errorf("Expected '.' or '@' after quoted, got '%s'", $c);
}

function LexEscaped($l) {
  // Ignore the '\'
  $l->ignore();
  if (!$l->accept(QPALLOWED))
    return $l->errorf("Input '%s' after escape char was not an escapable char", $c);
  if ($l->pos > $l->start) {
    $l->emit(ItemType::Escaped);
    return 'LexInsideQuoted';
  }
  return $l->errorf("No input after escape character");
}

function LexDot($l) {
  $l->emit(ItemType::Dot);
  return 'LexAtomish';
}

function LexAt($l) {
  $l->emit(ItemType::AtSign);
  $c = $l->peek();
  if ($c === '[') {
    $l->next();
    return 'LexLeftBracket';
  }
  if ($l->accept(ALLOWED))
    return 'LexAtom';
  return $l->errorf("Input '%s' after '@' is not '[' or an Atom", $c);
}

function LexLeftBracket($l) {
  $l->emit(ItemType::LeftBracket);
  return 'LexInsideBracket';
}

function LexInsideBracket($l) {
  $l->accept_run(CharRange(32, 90) . CharRange(94, 126) . chr(9));
  if ($l->pos > $l->start)
    $l->emit(ItemType::InsideBracketed);

  if ($l->peek() === ']') {
    $l->next();
    return 'LexRightBracket';
  }
  return $l->errorf("Expected ']' after bracketed, got '%s'", $l->peek());
}

function LexRightBracket($l) {
  $l->emit(ItemType::RightBracket);
  if ($l->peek() === EOF) {
    $l->emit(ItemType::EOF);
    return NULL;
  }
  return $l->errorf("Input remaining after end of bracketed domain");
}

function ValidEmail($addr) {
  $l = LexEmail("EmailLexer", $addr);

  /* Local portion */
  while (True) {
    $item = $l->next_item();
    if ($item->type !== ItemType::Atom and $item->type !== ItemType::Quote)
      return array(False, sprintf("Expected Atom or Quoted in local portion, got %s", $item->type));
    if ($item->type === ItemType::Quote) {
      while (True) {
        $item = $l->next_item();
        if ($item->type === ItemType::Quote)
          break;
        if ($item->type !== ItemType::QuotedText and
            $item->type !== ItemType::Escaped)
          return array(False, sprintf("Expected QuotedText or Escaped in quoted, got %s", $item->type));
      }
    }

    $item = $l->next_item();
    if ($item->type === ItemType::AtSign)
      break;
    if ($item->type !== ItemType::Dot)
      return array(False, sprintf("Expected Dot or AtSign after Atomish, got %s", $item->type));
  }

  /* Domain portion */
  $item = $l->next_item();
  if ($item->type === ItemType::LeftBracket) {
    $item = $l->next_item();
    if ($item->type === ItemType::InsideBracketed)
      $item = $l->next_item();
    if ($item->type !== ItemType::RightBracket)
      return array(False, sprintf("Expected RightBracket after LeftBracket or Bracketed, got %s", $item->type));
    $item = $l->next_item();
    if ($item->type !== ItemType::EOF)
      return array(False, sprintf("Expected end of input after RickBracket, got %s", $item->type));
    return array(True, "Success!");
  } elseif ($item->type === ItemType::Atom){
    while (True) {
      $item = $l->next_item();
      if ($item->type === ItemType::EOF)
        return array(True, "Success");
      if ($item->type !== ItemType::Dot)
        return array(False, sprintf("Expected EOF or Dot after Atom in domain, got %s", $item->type));

      $item = $l->next_item();
      if ($item->type !== ItemType::Atom)
        return array(False, sprintf("Expected Atom after Dot in domain portion, got %s", $item->type));
    }
  }
  return array(False, sprintf("Expected Atom or LeftBracket after '@', got %s", $item->type));
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
  array("gatsby@f.sc.ot.t.f.i.tzg.era.l.d.", False),
  array("me@\"mydomain\".com", False),
  array("me@[my very special domain]", True),
  array("me@[my very special].domain", False),
  array("me@[my very special]domain", False));

echo "<html><head><title>Email Validation Test</title><style>.pass { background-color: green; } .fail { background-color: red; }</style></head><body>";
echo "<h1>Email Validation Testcases</h1>";
echo "<table><tr><th>Email</th><th>Expected Value</th><th>Test Result</th></tr>";
foreach ($test_cases as $tc) {
  $rv = ValidEmail($tc[0]);
  if ($rv[0] === $tc[1])
    $res = '<td class="pass">PASS</td>';
  else
    $res = '<td class="fail">FAIL: <ul><li>' . $rv[1] . '</li></ul></td>';

  echo "<tr><td>" . $tc[0] . "</td>";
  if ($tc[1])
    echo "<td>True</td>";
  else
    echo "<td>False</td>";
  echo $res . "</tr>";
}
echo "</table></body></html>";
?>