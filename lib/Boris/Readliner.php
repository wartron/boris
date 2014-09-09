<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris;

use Hoa\Console\Readline\Readline as Readline;
use Hoa\Console\Cursor;
use Hoa\Console\Window;

/**
 * Add a few features to Hoa's Readline.
 */
class Readliner extends Readline {

  private $historyFile;
  private $workingLine;

  /**
   * Add key mappings.
   */
  public function __construct()
  {
    parent::__construct();

    $this->addMapping('\C-D', array($this, 'bindEOT'));
    $this->addMapping('\C-L', array($this, 'bindClear'));
    #$this->addMapping('\C-P', array($this, 'bindClear'));
  }

  /**
   * End of Transmission (EOT)
   */
  public function bindEOT($self)
  {
    $this->setLine(false); // mimic readline() - return false for ctrl+d
    return self::STATE_BREAK;
  }

  /**
   * Clear the window, except for the current line.
   */
  public function bindClear($self)
  {
    Cursor::clear('all');
    echo $this->getPrefix() . $this->getLine();
    return self::STATE_NO_ECHO;
  }

  /**
   * Init history.
   *
   * @access  public
   * @return  void
   */
  public function initHistory($file)
  {
    if (is_readable($file)) {
      $this->historyFile = $file;
      $lines = file($file, FILE_IGNORE_NEW_LINES);
      $size  = count($lines);
      $this->_history        = $lines;
      $this->_historyCurrent = $size;
      $this->_historySize    = $size;
    }
  }

  /**
   * Save history.
   *
   * @access  public
   * @return  void
   */
  public function saveHistory($blacklist = array())
  {
    if (is_writeable($this->historyFile)) {
      if (! is_array($blacklist)) {
        $blacklist = array();
      }
      $hs = array();
      $prev = null;
      foreach ($this->_history as $h) {
        // de-dupe: the user thinks up/down is broken/lagged; it's pointless.
        if ($h !== $prev && ! in_array($h, $blacklist)) {
          $prev = $h;
          $hs[] = $h;
        }
      }
      file_put_contents($this->historyFile, implode("\n", $hs));
    }
  }

  /**
   * Newline binding.
   *
   * @access  public
   * @param   \Hoa\Console\Readline  $self    Self.
   * @return  int
   */
  public function _bindNewline(Readline $self)
  {
    $self->addHistory($self->getLine());
    $this->_historyCurrent = $this->_historySize;
    return static::STATE_BREAK;
  }

  /**
   * Add an entry in the history.
   *
   * @access  public
   * @param   string  $line    Line.
   * @return  void
   */
  public function addHistory($line = null)
  {
    if (empty($line)) {
      return;
    }
    $this->_history[] = $line;
    if (count($this->_history) > 100) {
      array_shift($this->_history);
    }
    $this->_historySize = count($this->_history);
  }

  /**
   * Go backward in the history.
   *
   * @access  public
   * @return  string
   */
  public function previousHistory()
  {
    if (0 >= $this->_historyCurrent) {
        return $this->getHistory(0);
    }
    return $this->getHistory(--$this->_historyCurrent);
  }
  /**
   * Go forward in the history.
   *
   * @access  public
   * @return  string
   */
  public function nextHistory()
  {
    if ($this->_historyCurrent === $this->_historySize) {
        return $this->workingLine;
    }
    return $this->getHistory(++$this->_historyCurrent);
  }

  /**
   * Down arrow binding.
   * Go forward in the history.
   *
   * @access  public
   * @param   \Hoa\Console\Readline  $self    Self.
   * @return  int
   */
  public function _bindArrowDown(Readline $self)
  {
    if (0 === (static::STATE_CONTINUE & static::STATE_NO_ECHO)) {
      Cursor::clear('line');
      echo $self->getPrefix();
    }

    // User is already at the newest line and must be confused
    if ($this->_historyCurrent === $this->_historySize) {
      $buffer = $this->workingLine = $this->getLine();
    // User wants to resume where they left off before looking at history
    } else if ($this->_historyCurrent + 1 === $this->_historySize) {
      $this->_historyCurrent++;
      $buffer = $this->workingLine;
    // User wants to venture into the past
    } else {
      $buffer = $self->nextHistory();
    }
    $self->setLine($buffer);
    $self->setBuffer($buffer);
    return static::STATE_CONTINUE;
  }

  /**
   * Up arrow binding.
   * Go backward in the history.
   *
   * @access  public
   * @param   \Hoa\Console\Readline  $self    Self.
   * @return  int
   */
  public function _bindArrowUp(Readline $self)
  {
    if (0 === (static::STATE_CONTINUE & static::STATE_NO_ECHO)) {
      Cursor::clear('line');
      echo $self->getPrefix();
    }
    // User is working on something new, but decides to look at history
    if ($this->_historyCurrent === $this->_historySize) {
      // Save the current line so we can resume it
      $this->workingLine = $this->getLine();
    }
    $buffer = $self->previousHistory();
    $self->setBuffer($buffer);
    $self->setLine($buffer);
    return static::STATE_CONTINUE;
  }

  /**
   * Tab binding.
   *
   * @access  public
   * @param   Readline  $self    Self.
   * @return  int
   */
  public function _bindTab(Readline $self)
  {
    $autocompleter = $self->getAutocompleter();
    $state         = static::STATE_CONTINUE | static::STATE_NO_ECHO;

    if (null === $autocompleter) {
      return $state;
    }

    $current = $self->getLineCurrent();
    $line    = $self->getLine();

    // we need at least 1 char to work with
    if (0 === $current) {
      return $state;
    }

    $matches = preg_match_all(
      '#' . $autocompleter->getWordDefinition() . '#u',
      $line,
      $words,
      PREG_OFFSET_CAPTURE
    );

    if (0 === $matches) {
        return $state;
    }

    for ($i = 0, $max = count($words[1]);
         $i < $max && $current > $words[1][$i][1];
         ++$i) {
    }
    $word = $words[1][$i - 1];

    if ('' === trim($word[0])) {
        return $state;
    }

    $prefix = mb_substr($word[0], 0, $current - $word[1]);
    Debug::log('prefix', compact('line','current','words','word','prefix'));

    $solution = $autocompleter->complete($prefix);
    Debug::log('solution', $solution);

    if (null === $solution || empty($solution)) {
        return $state;
    }

    if (preg_match('/[\S]+(::|\->)([\S]*)/', $prefix, $m, PREG_OFFSET_CAPTURE)) {
      //Debug::log('matchPrefix', compact('prefix', 'm'));
      $suffixLength = mb_strlen($m[2][0]);
      $prefix       = mb_substr($prefix, -$suffixLength);
      $tail         = mb_substr($line, $current);
      $head         = mb_substr($line, 0, $current - $suffixLength);
      $line         = $head . $tail;
      $current     -= $suffixLength;
      $length       = $suffixLength;
    } else {
      $length   = mb_strlen($prefix);
      $tail     = mb_substr($line, $current);
      $head     = mb_substr($line, 0, $current - $length);
      $line     = $head . $tail;
      $current -= $length;
    }

    //Debug::log('completionBefore', compact('prefix','line','current','tail','length'));
    if (is_array($solution)) {
      if (count($solution) === 1) {

        $line = $head . $solution[0] . $tail;
        $self->setLine($line);
        $self->setLineCurrent($current + mb_strlen($solution[0]));
        $self->setBuffer($line);

        Cursor::move('left', $length);
        echo $solution[0];
        Cursor::clear('right');
        echo $tail;
        Cursor::move('left', mb_strlen($tail));

        Debug::log('completion', array(
          'line'    =>$self->getLine(),
          'current' =>$self->getLineCurrent(),
          'buffer'  =>$self->getBuffer(),
        ));
        return $state;
      }

      $_solution = $solution;
      $count     = count($_solution) - 1;
      $cWidth    = 0;
      $window    = Window::getSize();
      $wWidth    = $window['x'];
      $cursor    = Cursor::getPosition();

      array_walk($_solution, function ( &$value ) use ( &$cWidth ) {
          $handle = mb_strlen($value);
          if ($handle > $cWidth) {
              $cWidth = $handle;
          }
      });
      array_walk($_solution, function (&$value) use (&$cWidth) {
          $handle = mb_strlen($value);
          if ($handle >= $cWidth) {
              return;
          }
          $value .= str_repeat(' ', $cWidth - $handle);
      });

      $mColumns = (int) floor($wWidth / ($cWidth + 2));
      $mLines   = (int) ceil(($count + 1) / $mColumns);
      --$mColumns;

      $pos = Cursor::getPosition();
      if (($window['y'] - $cursor['y'] - $mLines) < 0) {
        #Debug::log("here", compact('window','cursor','mLines','mColumns'));
        echo str_repeat("\n", $mLines + 1);
        Cursor::move('up', $mLines);
        Cursor::clear('LEFT');
        echo $this->getPrefix() . $this->getLine() . "\n";
        Cursor::move('up LEFT');
        Cursor::move('right', $pos['x'] - 1);
      }

      Cursor::save();
      Cursor::hide();
      Cursor::move('down LEFT');
      Cursor::clear('down');

      $i = 0;
      foreach ($_solution as $j => $s) {
          echo "\033[0m", $s, "\033[0m";
          if ($i++ < $mColumns) {
              echo '  ';
          } else {
              $i = 0;
              if (isset($_solution[$j + 1])) {
                  echo "\n";
              }
          }
      }

      Cursor::restore();
      Cursor::show();

      ++$mColumns;
      $read     = array(STDIN);
      $mColumn  = -1;
      $mLine    = -1;
      $coord    = -1;
      $unselect = function () use ( &$mColumn, &$mLine, &$coord,
                                    &$_solution, &$cWidth ) {
          Cursor::save();
          Cursor::hide();
          Cursor::move('down LEFT');
          Cursor::move('right', $mColumn * ($cWidth + 2));
          Cursor::move('down', $mLine);
          echo "\033[0m" . $_solution[$coord] . "\033[0m";
          Cursor::restore();
          Cursor::show();
      };
      $select = function () use ( &$mColumn, &$mLine, &$coord,
                                  &$_solution, &$cWidth ) {
          Cursor::save();
          Cursor::hide();
          Cursor::move('down LEFT');
          Cursor::move('right', $mColumn * ($cWidth + 2));
          Cursor::move('down', $mLine);
          echo "\033[7m" . $_solution[$coord] . "\033[0m";
          Cursor::restore();
          Cursor::show();
      };

      $init = function () use (&$mColumn, &$mLine, &$coord, &$select) {

          $mColumn = 0;
          $mLine   = 0;
          $coord   = 0;
          $select();
      };

      while (true) {
          @stream_select($read, $write, $except, 30, 0);

          if (empty($read)) {
              $read = array(STDIN);
              continue;
          }

          switch ($char = $self->_read()) {

              case "\033[A":
                  if (-1 === $mColumn && -1 === $mLine) {
                      $init();
                      break;
                  }
                  $unselect();
                  $coord   = max(0, $coord - $mColumns);
                  $mLine   = (int) floor($coord / $mColumns);
                  $mColumn = $coord % $mColumns;
                  $select();
                break;

              case "\033[B":
                  if (-1 === $mColumn && -1 === $mLine) {
                      $init();
                      break;
                  }
                  $unselect();
                  $coord   = min($count, $coord + $mColumns);
                  $mLine   = (int) floor($coord / $mColumns);
                  $mColumn = $coord % $mColumns;
                  $select();
                break;

              case "\t":
              case "\033[C":
                  if (-1 === $mColumn && -1 === $mLine) {
                      $init();
                      break;
                  }
                  $unselect();
                  $coord   = min($count, $coord + 1);
                  $mLine   = (int) floor($coord / $mColumns);
                  $mColumn = $coord % $mColumns;
                  $select();
                break;

              case "\033[D":
                  if (-1 === $mColumn && -1 === $mLine) {
                      $init();
                      break;
                  }
                  $unselect();
                  $coord   = max(0, $coord - 1);
                  $mLine   = (int) floor($coord / $mColumns);
                  $mColumn = $coord % $mColumns;
                  $select();
                break;

              case "\n":
                  if (-1 !== $mColumn && -1 !== $mLine) {

                      $self->setLine(
                          $head .
                          $solution[$coord] .
                          $tail
                      );
                      $self->setLineCurrent(
                          $current + mb_strlen($solution[$coord])
                      );

                      Cursor::move('left', $length);
                      echo $solution[$coord];
                      Cursor::clear('right');
                      echo $tail;
                      Cursor::move('left', mb_strlen($tail));
                  }

              default:
                  $mColumn = -1;
                  $mLine   = -1;
                  $coord   = -1;
                  Cursor::save();
                  Cursor::move('down LEFT');
                  Cursor::clear('down');
                  Cursor::restore();

                  if("\033" !== $char && "\n" !== $char) {

                      $self->setBuffer($char);

                      return $self->_readLine($char);
                  }

                  break 2;
          }
      }
      return $state;
    }
  }
}