
Logic bugs:
- [ ] getPsr0NamespaceString() passes string|string[] to trim() (Types/NamespacedSymbol.php:105) — PSR-0 allows array paths; PHP 8 will throw a TypeError.

Error handling:
- [ ] Invalid regex in MarkFilesExcludedFromChanges silently matches nothing (line 50) — bad user patterns are treated as "exclude nothing" with no warning.

Type design:
- [ ] ENUM_SYMBOL is a dead code path — bucket exists, no concrete EnumSymbol routes to it, getEnum() always returns null.

Test Gaps
- [ ] Enum scanning is unimplemented with no test documenting the gap — FileSymbolScanner.php:253 has // TODO: enum. but parity tests mask it (both scanners return nothing, so parity passes).
- [ ] DiscoveredSymbols::getToRename() has no dedicated unit test — called in ~10 Prefixer/ChangeEnumerator call sites; its new "skip if replacement equals original" guard is untested.
- [ ] No unit test for Prefixer::replaceInFile() early-return on doUpdate=false — the MarkFilesExcludedFromChanges → Prefixer boundary is only exercised at integration level.
- [ ] DependenciesEnumeratorBehaviorTest is in tests/Unit/ but calls exec('composer install') — misleading placement, environment-dependent, fails without Composer on PATH.
- [ ] MarkFilesExcludedFromChangesTest has misleading comment about a non-existent bug (line 38-40) — File::setDoUpdate() works correctly; assertNotTrue should be assertFalse.


PR Review Summary

Critical Issues (5)

[silent-failure-hunter] try { } finally { return ... } in 7 read methods swallows all exceptions — fileExists, read, readStream, visibility, mimeType, lastModified,
fileSize all use this pattern. A broken symlink during fileExists() silently returns false instead of propagating the error, so callers write to unexpected paths.
Replace finally { return ... } with catch (RuntimeException $e) { ... } then an unconditional return.

[silent-failure-hunter] write() and writeStream() call getSymlinkInPath() / getSymlinkDetails() with no exception handling. A broken symlink throws a bare
RuntimeException("symlink error") instead of UnableToWriteFile. Compare to move() and copy() which catch and rethrow correctly.

[silent-failure-hunter] deleteDirectory() bypasses the linkHandling switch for the "path is itself the symlink" case. Under SKIP_LINKS or DISALLOW_LINKS the symlink is
removed anyway — those cases are dead code. delete() puts the isSymlink() check inside the switch correctly; deleteDirectory() inverts this, making
SKIP_LINKS/DISALLOW_LINKS silent no-ops for directory symlinks.

[code-reviewer] NamespacedSymbol.php:102 — trim($autoloadPackageRelativePath, '\\/') throws TypeError on PHP 8 when a PSR-0 autoload entry maps to an array of paths
(Composer allows "Foo\\": ["src/", "lib/"]). PHPStan flags this. Fix: foreach ((array) $autoloadPackageRelativePath as $relPath).

[code-reviewer] PathPrefixerInterface doesn't declare stripPrefix(), but write() (line 297) and listContents() (line 799) call $this->pathPrefixer->stripPrefix(). Any
interface-conforming injected prefixer causes a fatal "undefined method" at runtime.

Important Issues (9)

[type-design-analyzer] @phpstan-type PathSymlinkDetailsArray lists 4 keys with wrong names (path, realpath, symlinkPath) that don't match the 5 actual property names.
All 7+ (array) $symlinkDetails call sites in the adapter reason against a non-existent type shape. Fix the alias and replace all casts with a toArray() method.

[type-design-analyzer / silent-failure-hunter] write() strips prefix then re-passes to getSymlinkDetails() (which re-prefixes) — correct. But writeStream() passes the
raw absolute $symlink path directly to getSymlinkDetails(), which double-prefixes it. realpath() on the double-prefixed path returns false → throws RuntimeException. The
two sibling methods are inconsistent and one is broken.

[silent-failure-hunter] getSymlinkInPath() throws new \Exception('symlink error') (line 192) — a bare \Exception with no path context. Callers move() and copy() catch
RuntimeException, so this \Exception escapes unhandled. Change to RuntimeException and include $absoluteFilesystemPath in the message.

[silent-failure-hunter] isSymlinked() calls getSymlinkDetails() without catching RuntimeException. A non-existent path throws instead of returning false. Public API
should not throw RuntimeException for an existence check.

[silent-failure-hunter] copy() under THROW_LINKS when the source is symlinked just breaks — the copy proceeds. Inconsistent with move() which throws. Whether
source-symlink should throw for copy is a design decision, but it should be documented or matched.

[silent-failure-hunter] move() WARN_LINKS logs an empty $logMessage (the string-building code was noted in my earlier review — check line 584 for whether the appends
exist now; the agent found the $logMessage = '' pattern with context but no actual message string).

[silent-failure-hunter] removeSymlink() calls realpath($fullPath) in the log message before unlinking — if the symlink is broken, realpath() returns false and logs
"points to " (empty). rmdir()/unlink() return values are not logged on failure.

[tests] WARN_LINKS write/delete paths are entirely untested. filesystemWarn is set up but only used in one test (test_delete_symlinked_directory). No test confirms that
WARN_LINKS actually writes the file after logging the warning.

[tests] move() and copy() have zero tests. The asymmetric copy behaviour (source-symlink allowed, destination-symlink throws) is a non-obvious contract with no
regression guard.

Suggestions (6)

[tests] Broken/dangling symlink handling untested — no test creates a symlink whose target doesn't exist and then calls write/delete/move.

[tests] DiscoveredSymbols::offsetExists() duplicate-symbol exception path, get(), has(), and Psr0NamespaceSymbol routing through add() are all untested.

[tests] tearDown() should also assert fakedir symlink still exists after non-deleting tests.

[type-design-analyzer] absoluteFilesystemPath is derivable from flysystemPath + prefixer; symlinkTargetRealpathAbsoluteFilesystemPath is derivable from
symlinkAbsoluteFilesystemPath via realpath(). Removing redundant constructor params eliminates inconsistency risk. Ratings: Encapsulation 2/10, Invariant Expression
2/10, Usefulness 6/10, Enforcement 1/10.

[code-reviewer] listContents() line 803: octdec() returns float|int; cast to (int) to match parent adapter's type expectations.

[code-reviewer] PrefixComposerAutoloadFilesCommand and ReplaceCommand catch bare Exception and log only getMessage() — missing getFile()/getLine() context added to
DependenciesCommand in this same PR.

Strengths

- The WARN_LINKS / THROW_LINKS mode split is the right design — it separates "log and continue" from "hard stop."
- PathSymlinkDetails.isSymlink() correctly distinguishes "path is the symlink" from "path is inside a symlink" — used well in delete().
- The sibling-directory regression test (test_sibling_directory_not_treated_as_inside_symlink) is exactly the right shape: documents a named bug, verifies both the
  blocked and allowed outcomes.
- DiscoveredSymbols::offsetExists() rewrite from broken in_array(string, objects, strict) to isset($symbols[$offset]) is correct.
- tearDown() asserting real files survived every test is a strong guard.

Recommended Action

1. Fix try/finally exception swallowing in read methods

- The WARN_LINKS / THROW_LINKS mode split is the right design — it separates "log and continue" from "hard stop."
- PathSymlinkDetails.isSymlink() correctly distinguishes "path is the symlink" from "path is inside a symlink" — used well in delete().
- The sibling-directory regression test (test_sibling_directory_not_treated_as_inside_symlink) is exactly the right shape: documents a named bug, verifies both the
  blocked and allowed outcomes.
- DiscoveredSymbols::offsetExists() rewrite from broken in_array(string, objects, strict) to isset($symbols[$offset]) is correct.
- tearDown() asserting real files survived every test is a strong guard.

Recommended Action

1. Fix try/finally exception swallowing in read methods
2. Wrap getSymlinkInPath() calls in write()/writeStream() with typed rethrows
3. Fix writeStream()/write() path inconsistency for getSymlinkDetails() argument
4. Fix deleteDirectory() to respect linkHandling for the isSymlink() case
5. Add stripPrefix() to PathPrefixerInterface
6. Fix @phpstan-type alias and add toArray() to PathSymlinkDetails
7. Fix NamespacedSymbol::getPsr0NamespaceString() array path TypeError
8. Add WARN_LINKS write/delete tests, move/copy tests, broken-symlink tests

