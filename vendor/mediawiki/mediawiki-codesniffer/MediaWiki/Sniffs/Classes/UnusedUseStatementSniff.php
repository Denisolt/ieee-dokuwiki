<?php
/**
 * Originally from Drupal's coding standard <https://github.com/klausi/coder>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Sniffs\Classes;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

class UnusedUseStatementSniff implements Sniff {

	/**
	 * Doc tags where a class name is used
	 *
	 * @var string[]
	 */
	private $classTags = [
		'@expectedException',
		'@param',
		'@return',
		'@throws',
		'@var',
		// Deprecated
		'@type',
	];

	/**
	 * Returns an array of tokens this test wants to listen for.
	 *
	 * @return array
	 */
	public function register() {
		return [ T_USE ];
	}

	/**
	 * Processes this test, when one of its tokens is encountered.
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int $stackPtr The position of the current token in
	 *                                        the stack passed in $tokens.
	 *
	 * @return void
	 */
	public function process( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();

		// Only check use statements in the global scope.
		if ( empty( $tokens[$stackPtr]['conditions'] ) === false ) {
			return;
		}

		// Seek to the end of the statement and get the string before the semi colon.
		$semiColon = $phpcsFile->findEndOfStatement( $stackPtr );
		if ( $tokens[$semiColon]['code'] !== T_SEMICOLON ) {
			return;
		}

		$classPtr = $phpcsFile->findPrevious(
			Tokens::$emptyTokens,
			( $semiColon - 1 ),
			null,
			true
		);

		if ( $tokens[$classPtr]['code'] !== T_STRING ) {
			return;
		}

		// Search where the class name is used. PHP treats class names case
		// insensitive, that's why we cannot search for the exact class name string
		// and need to iterate over all T_STRING tokens in the file.
		$classUsed = $phpcsFile->findNext( T_STRING, ( $classPtr + 1 ) );
		$lowerClassName = strtolower( $tokens[$classPtr]['content'] );

		// Check if the referenced class is in the same namespace as the current
		// file. If it is then the use statement is not necessary.
		$namespacePtr = $phpcsFile->findPrevious( [ T_NAMESPACE ], $stackPtr );
		// Check if the use statement does aliasing with the "as" keyword. Aliasing
		// is allowed even in the same namespace.
		$aliasUsed = $phpcsFile->findPrevious( T_AS, ( $classPtr - 1 ), $stackPtr );

		if ( $namespacePtr !== false && $aliasUsed === false ) {
			$nsEnd = $phpcsFile->findNext(
				[
				 T_NS_SEPARATOR,
				 T_STRING,
				 T_WHITESPACE,
				],
				( $namespacePtr + 1 ),
				null,
				true
			);
			$namespace = trim(
				$phpcsFile->getTokensAsString( ( $namespacePtr + 1 ), ( $nsEnd - $namespacePtr - 1 ) )
			);

			$useNamespacePtr = $phpcsFile->findNext( [ T_STRING ], ( $stackPtr + 1 ) );
			$useNamespaceEnd = $phpcsFile->findNext(
				[
				 T_NS_SEPARATOR,
				 T_STRING,
				],
				( $useNamespacePtr + 1 ),
				null,
				true
			);
			$use_namespace = rtrim(
				$phpcsFile->getTokensAsString( $useNamespacePtr, ( $useNamespaceEnd - $useNamespacePtr - 1 ) ),
				'\\'
			);

			if ( strcasecmp( $namespace, $use_namespace ) === 0 ) {
				$classUsed = false;
			}
		}

		while ( $classUsed !== false ) {
			if ( strtolower( $tokens[$classUsed]['content'] ) === $lowerClassName ) {
				// If the name is used in a PHP 7 function return type declaration
				// stop.
				if ( $tokens[$classUsed]['code'] === T_RETURN_TYPE ) {
					return;
				}

				$beforeUsage = $phpcsFile->findPrevious(
					Tokens::$emptyTokens,
					( $classUsed - 1 ),
					null,
					true
				);
				// If a backslash is used before the class name then this is some other
				// use statement.
				if ( $tokens[$beforeUsage]['code'] !== T_USE
					&& $tokens[$beforeUsage]['code'] !== T_NS_SEPARATOR
				) {
					return;
				}

				// Trait use statement within a class.
				if ( $tokens[$beforeUsage]['code'] === T_USE
					&& empty( $tokens[$beforeUsage]['conditions'] ) === false
				) {
					return;
				}
			}

			// Usage in a doc comment
			if ( $tokens[$classUsed]['code'] === T_DOC_COMMENT_TAG
				&& in_array( $tokens[$classUsed]['content'], $this->classTags )
				&& $tokens[$classUsed + 1]['code'] === T_DOC_COMMENT_WHITESPACE
				&& $tokens[$classUsed + 2]['code'] === T_DOC_COMMENT_STRING
			) {
				// For tags like "@param ClassName $var Description" we just want the class name
				$exploded = explode( ' ', $tokens[$classUsed + 2]['content'] );
				// Now explode stuff like @var Class1|Class2
				$classes = explode( '|', $exploded[0] );
				foreach ( $classes as $tagClassName ) {
					// Handle @var ClassName[]
					if ( substr( $tagClassName, -2 ) === '[]' ) {
						$tagClassName = substr( $tagClassName, 0, -2 );
					}
					if ( strtolower( $tagClassName ) === $lowerClassName ) {
						return;
					}
				}
			}

			$classUsed = $phpcsFile->findNext(
				[ T_STRING, T_RETURN_TYPE, T_DOC_COMMENT_TAG ],
				( $classUsed + 1 )
			);
		}

		$warning = 'Unused use statement';
		$fix = $phpcsFile->addFixableWarning( $warning, $stackPtr, 'UnusedUse' );
		if ( $fix === true ) {
			// Remove the whole use statement line.
			$phpcsFile->fixer->beginChangeset();
			for ( $i = $stackPtr; $i <= $semiColon; $i++ ) {
				$phpcsFile->fixer->replaceToken( $i, '' );
			}

			// Also remove whitespace after the semicolon (new lines).
			while ( isset( $tokens[$i] ) === true && $tokens[$i]['code'] === T_WHITESPACE ) {
				$phpcsFile->fixer->replaceToken( $i, '' );
				if ( strpos( $tokens[$i]['content'], $phpcsFile->eolChar ) !== false ) {
					break;
				}

				$i++;
			}

			$phpcsFile->fixer->endChangeset();
		}
	}

}
